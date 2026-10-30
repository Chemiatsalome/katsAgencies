<?php

namespace App\Services;

use App\Models\DepositDeduction;
use App\Models\DepositPayment;
use App\Models\DepositRefund;
use App\Models\Document;
use App\Models\Tenancy;
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

    public function recordPayment(Tenancy $tenancy, float $amount, string $date, string $method, ?string $reference, ?string $notes, int $recordedBy): DepositPayment
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
        ]);
    }

    /**
     * Record a deduction against the held deposit at any point during (or
     * after) the tenancy — not limited to move-out. Refuses to deduct more
     * than is currently held, since that would mean deducting money that
     * was never actually paid in.
     */
    public function recordDeduction(Tenancy $tenancy, float $amount, string $reason, string $date, int $recordedBy, ?UploadedFile $evidence = null): DepositDeduction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Deduction amount must be greater than zero.');
        }

        // Locks the tenancy row for the rest of this transaction so two
        // concurrent requests (a double-click, two open tabs) can't both
        // read the same pre-write held balance and both pass the check
        // below, over-deducting past what's actually held.
        $deduction = DB::transaction(function () use ($tenancy, $amount, $reason, $date, $recordedBy) {
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
            'deposit_deduction_recorded',
            'Deposit Deduction Recorded',
            'KSh '.number_format($amount, 2)." was deducted from your held security deposit: {$reason}",
            ['in_app', 'email'],
            $tenancy,
        );

        return $deduction;
    }

    /** Void a deduction that was recorded in error or is under genuine dispute — kept on record, just excluded from the held-balance math from now on. */
    public function voidDeduction(DepositDeduction $deduction, string $reason, int $voidedBy): void
    {
        if ($deduction->isVoided()) {
            throw new InvalidArgumentException('This deduction has already been voided.');
        }

        $deduction->forceFill([
            'voided_at' => now(),
            'void_reason' => $reason,
            'voided_by' => $voidedBy,
        ])->save();
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
