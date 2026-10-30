<?php

namespace App\Services;

use App\Models\TenantLiabilityDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tier 2 (design approved 2026-10-25): the single writer of
 * tenant_liability_decisions rows. Occupancy (Tenancy::occupyingOn()) is a
 * candidate signal fed into the UI only — it has no write path here at all.
 * Exactly one active (unreversed) decision can ever exist per decidable
 * record: enforced primarily by locking the decidable row itself before
 * reading/writing (there's no decision row to lock before it exists, so the
 * parent record is the mutex), backed by a database-level unique index on a
 * generated column where the engine supports it (see the migration — MariaDB
 * yes, the SQLite test driver not relied on for this).
 */
class TenantLiabilityDecisionService
{
    public function activeDecisionFor(Model $decidable): ?TenantLiabilityDecision
    {
        return TenantLiabilityDecision::where('decidable_type', $decidable->getMorphClass())
            ->where('decidable_id', $decidable->getKey())
            ->active()
            ->first();
    }

    /**
     * Create the first decision for a decidable record, or amend an
     * existing one — modeled identically either way: reverse whatever is
     * currently active (if anything), then create the new row, in that
     * order (reversing first is what lets the database-level unique index
     * ever accept the new row — both rows would collide on the same
     * generated "active" key if the new one were inserted before the old
     * one stopped being active). `tenant_liable` and `landlord_responsible`
     * are not independent flags; whichever this call creates becomes the
     * one single active slot.
     *
     * Blocked outright if the current active decision already has a
     * non-voided deposit deduction against it — money has moved; the
     * deduction must be voided (DepositService::voidDeduction()) before who
     * is liable can be changed.
     */
    public function decide(Model $decidable, string $decisionType, ?int $tenancyId, string $reason, int $decidedBy): TenantLiabilityDecision
    {
        if (! in_array($decisionType, ['tenant_liable', 'landlord_responsible'], true)) {
            throw new InvalidArgumentException("Invalid decision_type: {$decisionType}");
        }

        if ($decisionType === 'tenant_liable' && ! $tenancyId) {
            throw new InvalidArgumentException('A tenant_liable decision requires a tenancy.');
        }

        if ($decisionType === 'landlord_responsible' && $tenancyId) {
            throw new InvalidArgumentException('A landlord_responsible decision never names a tenancy.');
        }

        return DB::transaction(function () use ($decidable, $decisionType, $tenancyId, $reason, $decidedBy) {
            // The decidable record is the mutex — there's no decision row
            // to lock before one exists, and this also correctly serializes
            // two concurrent attempts to decide on the same expense/task.
            $decidable::whereKey($decidable->getKey())->lockForUpdate()->first();

            $current = $this->activeDecisionFor($decidable);

            if ($current && $this->hasUnvoidedDeduction($decidable)) {
                throw new RuntimeException(
                    'This expense already has a deposit deduction recorded against the current decision — void the deduction first before changing who\'s liable.'
                );
            }

            if ($current) {
                $current->forceFill([
                    'reversed_at' => now(),
                    'reversed_by' => $decidedBy,
                    'reversal_reason' => "Superseded by a new {$decisionType} decision.",
                ])->save();
            }

            $new = TenantLiabilityDecision::create([
                'decidable_type' => $decidable->getMorphClass(),
                'decidable_id' => $decidable->getKey(),
                'decision_type' => $decisionType,
                'tenancy_id' => $tenancyId,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'reason' => $reason,
            ]);

            $current?->update(['superseded_by_id' => $new->id]);

            return $new;
        });
    }

    /**
     * Reverse a decision with no replacement — "actually, no decision
     * applies here after all." Same deduction guard as decide(). Idempotent:
     * reversing an already-reversed decision is a no-op, not an error.
     */
    public function reverse(TenantLiabilityDecision $decision, string $reason, int $actorId): bool
    {
        return DB::transaction(function () use ($decision, $reason, $actorId) {
            $decidableClass = $decision->decidable_type;
            $decidableClass::whereKey($decision->decidable_id)->lockForUpdate()->first();

            $decision->refresh();

            if (! $decision->isActive()) {
                return false;
            }

            if ($this->hasUnvoidedDeduction($decision->decidable)) {
                throw new RuntimeException(
                    'This expense already has a deposit deduction recorded against this decision — void the deduction first.'
                );
            }

            return TenantLiabilityDecision::whereKey($decision->id)->whereNull('reversed_at')->update([
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => $reason,
            ]) > 0;
        });
    }

    private function hasUnvoidedDeduction(Model $decidable): bool
    {
        $deduction = $decidable->depositDeduction;

        return $deduction && ! $deduction->isVoided();
    }
}
