<?php

namespace App\Services;

use App\Models\DepositDeduction;
use App\Models\DepositPayment;
use App\Models\DepositRefund;
use App\Models\Document;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Everything to do with a tenancy's security deposit — kept entirely
 * separate from rent (Payment/RentCharge). A tenant's rent balance reaching
 * zero must never be read as "nothing outstanding" when their deposit
 * isn't fully paid; every figure here is reported independently for that
 * reason.
 */
class DepositService
{
    public function outstanding(Tenancy $tenancy): float
    {
        return $tenancy->depositOutstanding();
    }

    public function heldBalance(Tenancy $tenancy): float
    {
        return $tenancy->depositHeldBalance();
    }

    public function deductedTotal(Tenancy $tenancy): float
    {
        return $tenancy->depositDeductedTotal();
    }

    public function refundedTotal(Tenancy $tenancy): float
    {
        return $tenancy->depositRefundedTotal();
    }

    /** Notify the tenant that the landlord wants the remaining deposit paid, and timestamp the request. */
    public function requestOutstanding(Tenancy $tenancy): void
    {
        $outstanding = $this->outstanding($tenancy);

        if ($outstanding <= 0) {
            throw new InvalidArgumentException('This tenancy\'s deposit is already fully paid — there\'s nothing to request.');
        }

        $tenancy->forceFill(['deposit_request_sent_at' => now()])->save();

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'deposit_requested',
            'Security Deposit Payment Requested',
            'Your landlord has requested the remaining KSh '.number_format($outstanding, 2).' of your security deposit. Please arrange payment.',
            ['in_app', 'email'],
            $tenancy,
        );
    }

    /**
     * $status defaults to 'confirmed' so both existing callers (an M-Pesa
     * success callback, already verified by Safaricom; a landlord's own
     * RecordDeposit entry, their own say-so) keep crediting the ledger
     * immediately exactly as before. Only a tenant's own unverified offline
     * claim (see Tenant\PayDeposit::submitOfflinePayment()) ever passes
     * 'awaiting_landlord_confirmation' — excluded from Tenancy.deposit_paid
     * until a landlord confirms it (see DepositPaymentObserver).
     */
    public function recordPayment(Tenancy $tenancy, float $amount, string $date, string $method, ?string $reference, ?string $notes, int $recordedBy, string $status = 'confirmed'): DepositPayment
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return DepositPayment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => $amount,
            'payment_date' => $date,
            'method' => $method,
            'reference_number' => $reference,
            'recorded_by' => $recordedBy,
            'notes' => $notes,
            'status' => $status,
        ]);
    }

    /** The landlord acknowledges a tenant's offline deposit-payment claim actually arrived. Mirrors Tenancies\Show::confirmPayment() exactly. */
    public function confirmPayment(DepositPayment $payment, User $landlord): void
    {
        if ($payment->status === 'confirmed') {
            return;
        }

        $payment->forceFill([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => $landlord->id,
        ])->save();
    }

    /** The landlord doesn't accept this claim as-is — reachable from either 'awaiting_landlord_confirmation' or an already-'confirmed' claim that turns out to be wrong. Mirrors Tenancies\Show::disputePayment() exactly. */
    public function disputePayment(DepositPayment $payment, User $landlord, string $reason): void
    {
        $payment->forceFill([
            'status' => 'disputed',
            'disputed_at' => now(),
            'dispute_reason' => $reason,
            'disputed_by' => $landlord->id,
        ])->save();
    }

    /**
     * Record a deduction against the held deposit at any point during (or
     * after) the tenancy — not limited to move-out. Refuses to deduct more
     * than is currently held, since that would mean deducting money that
     * was never actually paid in.
     *
     * @param  bool  $awaitingTenantAcknowledgement  Direct maintenance/
     *                expense deposit charging: true for the new
     *                unified-form flows — the deduction is a proposal
     *                until the tenant acknowledges it (or a dispute is
     *                resolved in the landlord's favour), and does not yet
     *                count against the held balance (see
     *                Tenancy::depositDeductedTotal()). False (default)
     *                keeps every pre-existing caller's behaviour exactly as
     *                it was — auto-acknowledged, counted immediately.
     */
    public function recordDeduction(Tenancy $tenancy, float $amount, string $reason, string $date, int $recordedBy, ?UploadedFile $evidence = null, bool $awaitingTenantAcknowledgement = false): DepositDeduction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Deduction amount must be greater than zero.');
        }

        // Locks the tenancy row for the rest of this transaction so two
        // concurrent requests (a double-click, two open tabs) can't both
        // read the same pre-write held balance and both pass the check
        // below, over-deducting past what's actually held.
        $deduction = DB::transaction(function () use ($tenancy, $amount, $reason, $date, $recordedBy, $awaitingTenantAcknowledgement) {
            Tenancy::where('id', $tenancy->id)->lockForUpdate()->first();

            $held = $this->heldBalance($tenancy);

            if ($amount > $held + 0.01) {
                throw new InvalidArgumentException(
                    'That amount is more than KSh '.number_format($held, 2).' currently held for this deposit.'
                );
            }

            return DepositDeduction::create([
                'tenancy_id' => $tenancy->id,
                'amount' => $amount,
                'reason' => $reason,
                'deduction_date' => $date,
                'recorded_by' => $recordedBy,
                'acknowledged_at' => $awaitingTenantAcknowledgement ? null : now(),
            ]);
        });

        if ($evidence) {
            $path = $evidence->store('documents', 'local');

            Document::create([
                'documentable_type' => $deduction->getMorphClass(),
                'documentable_id' => $deduction->id,
                'category' => 'deposit_deduction_evidence',
                'original_filename' => $evidence->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $evidence->getMimeType(),
                'size' => $evidence->getSize(),
                'uploaded_by' => $recordedBy,
            ]);
        }

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            $awaitingTenantAcknowledgement ? 'deposit_deduction_proposed' : 'deposit_deduction_recorded',
            $awaitingTenantAcknowledgement ? 'Proposed Deposit Deduction — Your Response Needed' : 'Deposit Deduction Recorded',
            $awaitingTenantAcknowledgement
                ? 'Your landlord proposes deducting KSh '.number_format($amount, 2)." from your security deposit: {$reason}. This is not yet confirmed — review it and acknowledge or dispute it."
                : 'KSh '.number_format($amount, 2)." was deducted from your held security deposit: {$reason}",
            ['in_app', 'email'],
            $tenancy,
        );

        return $deduction;
    }

    /**
     * The tenant agrees this deduction is fair — confirms it, which is what
     * actually makes it count against the held balance from now on (see
     * Tenancy::depositDeductedTotal()). Only meaningful for a deduction
     * still awaiting a first response; already-acknowledged, voided, or
     * disputed deductions each have their own distinct path.
     */
    public function acknowledgeDeduction(DepositDeduction $deduction, int $tenantId): void
    {
        if ($deduction->isVoided()) {
            throw new InvalidArgumentException('This deduction has been voided — there\'s nothing to acknowledge.');
        }

        if ($deduction->disputed_at !== null) {
            throw new InvalidArgumentException('This deduction is already disputed — withdraw the dispute first if you want to acknowledge it instead.');
        }

        if ($deduction->isAcknowledged()) {
            return;
        }

        $deduction->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by' => $tenantId,
        ])->save();

        $landlord = $deduction->tenancy->house->block->property->landlord;

        app(NotificationService::class)->notify(
            $landlord,
            'deposit_deduction_acknowledged',
            'Tenant Acknowledged a Proposed Deduction',
            $deduction->tenancy->tenant->name.' acknowledged the KSh '.number_format((float) $deduction->amount, 2).' deduction: '.$deduction->reason,
            ['in_app'],
            $deduction->tenancy,
        );
    }

    /**
     * Void a deduction that was recorded in error or is under genuine
     * dispute — kept on record, just excluded from the held-balance math
     * from now on.
     *
     * Direct maintenance/expense deposit charging: also corrects whichever
     * MaintenanceTask/Expense this deduction originated from (reverse
     * lookup, since DepositDeduction has no FK of its own back to either) —
     * previously neither was ever cleared, permanently locking that task/
     * expense out of ever being deducted again, and leaving the Work
     * Orders list showing "Deducted from tenant deposit" forever even
     * after voiding. The originating liability decision (if any) is also
     * reversed, so a fresh or amended decision is required before a new
     * deduction can be made — the old, now-superseded decision is never
     * silently reused.
     */
    public function voidDeduction(DepositDeduction $deduction, string $reason, int $voidedBy): void
    {
        if ($deduction->isVoided()) {
            throw new InvalidArgumentException('This deduction has already been voided.');
        }

        DB::transaction(function () use ($deduction, $reason, $voidedBy) {
            $deduction->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $voidedBy,
            ])->save();

            \App\Models\MaintenanceTask::where('deposit_deduction_id', $deduction->id)->update(['deposit_deduction_id' => null]);

            $expense = \App\Models\Expense::where('deposit_deduction_id', $deduction->id)->first();

            if ($expense) {
                $expense->update(['deposit_deduction_id' => null]);

                $decision = app(TenantLiabilityDecisionService::class)->activeDecisionFor($expense);

                if ($decision) {
                    app(TenantLiabilityDecisionService::class)->reverse(
                        $decision, "Deduction voided: {$reason}", $voidedBy,
                    );
                }
            }
        });
    }

    /**
     * The tenant disagrees with an already-recorded deduction — the amount,
     * the reason, or both. It stays on the record, but stops counting
     * against the held balance until the landlord resolves it one way or
     * the other, so neither side's figures silently move while it's in
     * dispute.
     */
    public function disputeDeduction(DepositDeduction $deduction, string $reason, int $disputedByTenantId): void
    {
        if ($deduction->isVoided()) {
            throw new InvalidArgumentException('This deduction has already been voided — there\'s nothing to dispute.');
        }

        if ($deduction->isDisputePending()) {
            throw new InvalidArgumentException('This deduction is already under dispute.');
        }

        $deduction->forceFill([
            'disputed_at' => now(),
            'dispute_reason' => $reason,
            'disputed_by' => $disputedByTenantId,
            'dispute_resolved_at' => null,
        ])->save();

        $landlord = $deduction->tenancy->house->block->property->landlord;

        app(NotificationService::class)->notify(
            $landlord,
            'deposit_deduction_disputed',
            'Tenant Disputed a Deposit Deduction',
            $deduction->tenancy->tenant->name.' disputed a KSh '.number_format((float) $deduction->amount, 2)." deduction: {$reason}",
            ['in_app', 'email'],
            $deduction->tenancy,
        );
    }

    /**
     * The landlord's response to a dispute: either uphold the deduction as
     * correct (clears the dispute, the deduction resumes counting against
     * the held balance) or void it outright (money goes back to held,
     * permanently, same as any other void).
     */
    public function resolveDispute(DepositDeduction $deduction, string $resolution, int $resolvedBy, ?string $voidReason = null): void
    {
        if (! $deduction->isDisputePending()) {
            throw new InvalidArgumentException('This deduction isn\'t currently under dispute.');
        }

        if ($resolution === 'void') {
            $this->voidDeduction($deduction, $voidReason ?: 'Disputed by tenant: '.$deduction->dispute_reason, $resolvedBy);
        }

        $deduction->forceFill(['dispute_resolved_at' => now()])->save();

        // disputeDeduction() keyed its landlord-facing alert to the tenancy
        // (not the deduction) since a deduction didn't exist as a
        // notification-worthy subject on its own — the event_type filter
        // keeps this from also clearing unrelated alerts about the same
        // tenancy (a payment, a profile update, etc.).
        app(NotificationService::class)->markRelatedRead(
            $deduction->tenancy,
            $deduction->tenancy->house->block->property->landlord,
            'deposit_deduction_disputed',
        );

        app(NotificationService::class)->notify(
            $deduction->tenancy->tenant,
            'deposit_dispute_resolved',
            'Your Deposit Deduction Dispute Was Resolved',
            $resolution === 'void'
                ? 'Your landlord voided the disputed KSh '.number_format((float) $deduction->amount, 2).' deduction.'
                : 'Your landlord reviewed your dispute and upheld the KSh '.number_format((float) $deduction->amount, 2).' deduction.',
            ['in_app', 'email'],
            $deduction->tenancy,
        );
    }

    /**
     * Record that some (or all) of the held deposit was paid back to the
     * tenant. Refuses to refund more than is currently held.
     */
    public function recordRefund(Tenancy $tenancy, float $amount, string $date, int $recordedBy, ?string $notes = null): DepositRefund
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        // Same held-balance race as recordDeduction() above — lock the
        // tenancy row for the duration of the check-then-write.
        $refund = DB::transaction(function () use ($tenancy, $amount, $date, $recordedBy, $notes) {
            Tenancy::where('id', $tenancy->id)->lockForUpdate()->first();

            $held = $this->heldBalance($tenancy);

            if ($amount > $held + 0.01) {
                throw new InvalidArgumentException(
                    'That amount is more than KSh '.number_format($held, 2).' currently held for this deposit.'
                );
            }

            return DepositRefund::create([
                'tenancy_id' => $tenancy->id,
                'amount' => $amount,
                'refund_date' => $date,
                'notes' => $notes,
                'recorded_by' => $recordedBy,
            ]);
        });

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'deposit_refunded',
            'Security Deposit Refunded',
            'KSh '.number_format($amount, 2).' of your security deposit has been refunded to you.',
            ['in_app', 'email'],
            $tenancy,
        );

        return $refund;
    }
}
