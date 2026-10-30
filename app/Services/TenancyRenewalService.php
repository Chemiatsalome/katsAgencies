<?php

namespace App\Services;

use App\Models\Tenancy;
use App\Models\TenancyRenewalRequest;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * A tenant's request to renew their tenancy agreement — distinct from
 * TenancyNotice's "extension of time to vacate": this is about renewing
 * the agreement itself. Approval always reuses ContractService's own
 * versioning (the same mechanism TenancyNotice::decide('approve_extension')
 * uses), so a renewed agreement is generated and superseded identically
 * regardless of which workflow triggered it.
 */
class TenancyRenewalService
{
    public function request(
        Tenancy $tenancy,
        int $tenantId,
        int $durationMonths,
        ?string $preferredNewEndDate,
        ?string $message,
    ): TenancyRenewalRequest {
        if ($tenancy->activeRenewalRequest()) {
            throw new InvalidArgumentException('There is already a renewal request in progress for this tenancy.');
        }

        $request = TenancyRenewalRequest::create([
            'tenancy_id' => $tenancy->id,
            'requested_duration_months' => $durationMonths,
            'preferred_new_end_date' => $preferredNewEndDate,
            'message' => $message,
            'status' => 'pending',
        ]);

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'tenancy_renewal_requested',
            'Tenant Requested a Tenancy Renewal',
            $tenancy->tenant->name.' would like to renew their tenancy for '.$durationMonths.' month(s).'.($message ? " \"{$message}\"" : ''),
            ['in_app', 'email'],
            $request,
        );

        return $request;
    }

    public function markUnderReview(TenancyRenewalRequest $request, int $byUserId): void
    {
        if (! $request->isOpen()) {
            throw new InvalidArgumentException('This renewal request is no longer open.');
        }

        $request->update(['status' => 'under_review']);
    }

    public function decide(TenancyRenewalRequest $request, int $decidedBy, string $decision, ?string $note = null): void
    {
        if (! in_array($decision, ['approve', 'decline'], true)) {
            throw new InvalidArgumentException('Invalid decision.');
        }

        if (! in_array($request->status, ['pending', 'under_review'], true)) {
            throw new InvalidArgumentException('There is no pending request to decide on.');
        }

        if ($decision === 'decline') {
            $request->update([
                'status' => 'declined',
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            app(NotificationService::class)->markRelatedRead($request, $this->landlordFor($request));
            $this->notifyDecision($request, 'declined', $note);

            return;
        }

        $this->applyApproval($request, $decidedBy, $request->requested_duration_months, $request->preferred_new_end_date?->format('Y-m-d'), $note);
        app(NotificationService::class)->markRelatedRead($request, $this->landlordFor($request));
        $this->notifyDecision($request, 'approved', $note);
    }

    public function proposeAlternative(
        TenancyRenewalRequest $request,
        int $decidedBy,
        int $altDurationMonths,
        ?string $altNewEndDate,
        ?string $altNote,
    ): void {
        if (! $request->isOpen()) {
            throw new InvalidArgumentException('This renewal request is no longer open.');
        }

        $request->update([
            'status' => 'alternative_proposed',
            'alternative_duration_months' => $altDurationMonths,
            'alternative_new_end_date' => $altNewEndDate,
            'alternative_note' => $altNote,
            'decided_by' => $decidedBy,
        ]);

        app(NotificationService::class)->markRelatedRead($request, $this->landlordFor($request));

        app(NotificationService::class)->notify(
            $request->tenancy->tenant,
            'tenancy_renewal_alternative_proposed',
            'Your Landlord Proposed Different Renewal Terms',
            'Your landlord proposed '.$altDurationMonths.' month(s) instead.'.($altNote ? " \"{$altNote}\"" : ''),
            ['in_app', 'email'],
            $request,
        );
    }

    public function respondToAlternative(TenancyRenewalRequest $request, int $tenantId, bool $accept): void
    {
        if ($request->status !== 'alternative_proposed') {
            throw new InvalidArgumentException('There is no alternative proposal to respond to.');
        }

        if (! $accept) {
            $request->update(['status' => 'declined']);

            app(NotificationService::class)->markRelatedRead($request, $request->tenancy->tenant);

            app(NotificationService::class)->notify(
                $this->landlordFor($request),
                'tenancy_renewal_alternative_responded',
                'Tenant Declined the Proposed Renewal Terms',
                $request->tenancy->tenant->name.' declined the alternative renewal terms.',
                ['in_app'],
                $request,
            );

            return;
        }

        $this->applyApproval(
            $request,
            $request->decided_by,
            $request->alternative_duration_months,
            $request->alternative_new_end_date?->format('Y-m-d'),
            $request->alternative_note,
        );

        app(NotificationService::class)->markRelatedRead($request, $request->tenancy->tenant);

        app(NotificationService::class)->notify(
            $this->landlordFor($request),
            'tenancy_renewal_alternative_responded',
            'Tenant Accepted the Proposed Renewal Terms',
            $request->tenancy->tenant->name.' accepted the alternative renewal terms — the agreement has been renewed.',
            ['in_app'],
            $request,
        );
    }

    private function applyApproval(
        TenancyRenewalRequest $request,
        int $decidedBy,
        int $durationMonths,
        ?string $preferredEndDate,
        ?string $note,
    ): void {
        $tenancy = $request->tenancy;
        $newEndDate = $preferredEndDate ?: ($tenancy->lease_end_date ?? Carbon::now())->copy()->addMonths($durationMonths)->format('Y-m-d');

        try {
            $newContract = app(ContractService::class)->generateForTenancy($tenancy);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('No default contract template is configured — generate or upload a renewed agreement manually before approving.');
        }

        $request->update([
            'status' => 'approved',
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'decision_note' => $note,
            'final_new_end_date' => $newEndDate,
            'new_contract_id' => $newContract->id,
        ]);

        $tenancy->update(['status' => 'active', 'lease_end_date' => $newEndDate]);
    }

    private function notifyDecision(TenancyRenewalRequest $request, string $decision, ?string $note): void
    {
        app(NotificationService::class)->notify(
            $request->tenancy->tenant,
            'tenancy_renewal_decision_made',
            $decision === 'approved' ? 'Your Tenancy Renewal Was Approved' : 'Your Tenancy Renewal Was Declined',
            $decision === 'approved'
                ? 'Your landlord approved your renewal. A renewed agreement is ready for your review.'
                : 'Your landlord declined your renewal request.'.($note ? " \"{$note}\"" : ''),
            ['in_app', 'email'],
            $request,
        );
    }

    private function landlordFor(TenancyRenewalRequest $request)
    {
        return $request->tenancy->house->block->property->landlord;
    }
}
