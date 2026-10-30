<?php

namespace App\Services;

use App\Models\Disbursement;
use App\Models\LandlordSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tracks what Kats Agencies owes each landlord for rent collected via
 * M-Pesa (into Kats' own paybill) but not yet paid out to them. Disbursing
 * is always a manual admin action recorded here after the real transfer —
 * there is no payment-gateway integration behind this yet.
 */
class DisbursementService
{
    /**
     * All-time rent collected via M-Pesa into Kats' own paybill on this
     * landlord's behalf — excludes 'direct' payments (settled straight into
     * the landlord's own shortcode), which were never Kats' to disburse.
     */
    public function collectedViaMpesa(int $landlordId): float
    {
        return (float) Payment::genuine()
            ->where('method', 'mpesa')
            ->where('collection_mode', 'platform')
            ->whereHas('tenancy.house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->sum('amount');
    }

    /** All-time rent collected via M-Pesa straight into the landlord's own shortcode — never Kats' to hold or disburse. */
    public function collectedDirectly(int $landlordId): float
    {
        return (float) Payment::genuine()
            ->where('method', 'mpesa')
            ->where('collection_mode', 'direct')
            ->whereHas('tenancy.house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->sum('amount');
    }

    /**
     * All-time amount already paid out to this landlord — every disbursement
     * that actually left Kats' hands, regardless of whether the landlord has
     * confirmed, disputed, or not yet responded to it. Excludes 'reversed'
     * (an admin correction, undoing an earlier entry) and 'failed' (recorded
     * but the transfer never actually completed) — those never happened as
     * far as what's owed is concerned.
     */
    public function totalDisbursed(int $landlordId): float
    {
        return (float) Disbursement::where('landlord_id', $landlordId)->countsTowardDisbursed()->sum('amount');
    }

    /** What Kats still owes this landlord right now. */
    public function owed(int $landlordId): float
    {
        return round($this->collectedViaMpesa($landlordId) - $this->totalDisbursed($landlordId), 2);
    }

    public function isPayoutConfigured(int $landlordId): bool
    {
        return (bool) LandlordSetting::get($landlordId, 'payout_shortcode');
    }

    /**
     * Disbursements go out on the 10th of each month — the number of days
     * from today until the next one (0 if today is the 10th).
     */
    public function daysUntilNextRelease(): int
    {
        $today = Carbon::today();
        $thisMonth = $today->copy()->startOfMonth()->addDays(9);
        $next = $today->greaterThan($thisMonth) ? $thisMonth->addMonthNoOverflow() : $thisMonth;

        return $today->diffInDays($next);
    }

    /** The most recent 10th-of-the-month release date that has already passed (today counts). */
    public function mostRecentReleaseDate(): Carbon
    {
        $today = Carbon::today();
        $thisMonthRelease = $today->copy()->startOfMonth()->addDays(9);

        return $today->greaterThanOrEqualTo($thisMonthRelease) ? $thisMonthRelease : $thisMonthRelease->subMonthNoOverflow();
    }

    /**
     * True once the most recent release date has passed with money still
     * owed and nothing recorded against it since — there's no stored
     * "this disbursement covers this period" link (recordDisbursement()
     * is a running balance, not period-based), so "since the last release
     * date" is the closest reliable proxy for "this cycle's payout never
     * went out" without changing how disbursements are recorded.
     */
    public function isOverdue(int $landlordId): bool
    {
        if ($this->owed($landlordId) <= 0) {
            return false;
        }

        return ! Disbursement::where('landlord_id', $landlordId)
            ->where('disbursed_at', '>=', $this->mostRecentReleaseDate()->toDateString())
            ->exists();
    }

    /**
     * Manually record that a landlord was paid out, after the real transfer
     * was made outside the system. Refuses to record more than is currently
     * owed — that would mean either a data error or double-entry, not a
     * legitimate disbursement. Starts 'awaiting_landlord_confirmation' —
     * recording it here is the admin's assertion that the transfer went
     * out, not the landlord's acknowledgement that it arrived.
     */
    public function recordDisbursement(
        int $landlordId,
        float $amount,
        ?string $reference,
        ?string $notes,
        int $recordedBy,
        ?string $payoutPeriodStart = null,
        ?string $payoutPeriodEnd = null,
        ?string $disbursedAt = null,
        ?string $destinationType = null,
        ?string $destinationShortcode = null,
    ): Disbursement {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Disbursement amount must be greater than zero.');
        }

        // Locks the landlord's User row for the rest of this transaction so
        // two concurrent recordDisbursement() calls (two admin tabs) can't
        // both read the same pre-write "owed" figure and both pass the
        // check below, over-disbursing past what's actually owed.
        return DB::transaction(function () use (
            $landlordId, $amount, $reference, $notes, $recordedBy,
            $payoutPeriodStart, $payoutPeriodEnd, $disbursedAt, $destinationType, $destinationShortcode,
        ) {
            User::where('id', $landlordId)->lockForUpdate()->first();

            $owed = $this->owed($landlordId);

            if ($amount > $owed + 0.01) {
                throw new InvalidArgumentException(
                    'That amount is more than KSh '.number_format($owed, 2)." — what's currently owed to this landlord."
                );
            }

            if ($reference && Disbursement::where('reference', $reference)->exists()) {
                throw new InvalidArgumentException("Reference \"{$reference}\" has already been recorded against a disbursement — check this isn't a duplicate entry.");
            }

            return Disbursement::create([
                'landlord_id' => $landlordId,
                'amount' => $amount,
                'disbursed_at' => $disbursedAt ?: now()->toDateString(),
                'status' => 'awaiting_landlord_confirmation',
                // Defaults to the landlord's currently configured payout
                // destination, but the admin can override it per transfer — the
                // actual transfer this one time might not match what's on file
                // (e.g. a one-off bank transfer for a landlord normally paid via
                // paybill). Always the snapshot of where THIS money actually
                // went, independent of whatever the setting says later.
                'destination_type' => $destinationType ?: LandlordSetting::get($landlordId, 'payout_shortcode_type'),
                'destination_shortcode' => $destinationShortcode ?: LandlordSetting::get($landlordId, 'payout_shortcode'),
                'reference' => $reference,
                'notes' => $notes,
                'recorded_by' => $recordedBy,
                'payout_period_start' => $payoutPeriodStart,
                'payout_period_end' => $payoutPeriodEnd,
            ]);
        });
    }

    /** The landlord acknowledges they received this transfer. Updates only the acknowledgement status — never the amount owed, which already moved when this was recorded. */
    public function confirm(Disbursement $disbursement, User $landlord): void
    {
        if ($disbursement->landlord_id !== $landlord->id) {
            throw new InvalidArgumentException('This payout does not belong to this landlord.');
        }

        $disbursement->fill([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => $landlord->id,
        ])->save();
    }

    /** The landlord disputes this transfer — missing, wrong amount, or wrong details. Flags it for admin review; does not by itself change what's owed. */
    public function dispute(Disbursement $disbursement, User $landlord, string $reason): void
    {
        if ($disbursement->landlord_id !== $landlord->id) {
            throw new InvalidArgumentException('This payout does not belong to this landlord.');
        }

        $disbursement->fill([
            'status' => 'disputed',
            'disputed_at' => now(),
            'dispute_reason' => $reason,
            'disputed_by' => $landlord->id,
        ])->save();
    }

    /**
     * Admin-only correction: this disbursement was recorded in error (or a
     * dispute was investigated and found to be right) — the transfer never
     * actually happened, so the amount goes back into what's owed. The row
     * itself is kept, never deleted, as the audit trail for the correction.
     */
    public function reverse(Disbursement $disbursement, User $admin, string $reason): void
    {
        $disbursement->fill([
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversal_reason' => $reason,
            'reversed_by' => $admin->id,
        ])->save();
    }

    /** Admin-only: the transfer was recorded but never actually completed (e.g. bounced back) — same effect as reverse(), kept as a distinct status for reporting. */
    public function markFailed(Disbursement $disbursement, User $admin, string $reason): void
    {
        $disbursement->fill([
            'status' => 'failed',
            'reversed_at' => now(),
            'reversal_reason' => $reason,
            'reversed_by' => $admin->id,
        ])->save();
    }
}
