<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use App\Models\TenancyNoticeEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Everything to do with issuing, responding to, and deciding a tenancy
 * notice. A notice is never deleted — withdrawn/superseded/resolved/closed
 * are terminal states that keep the row (and its TenancyNoticeEvent trail)
 * on record. Extensions of time-to-vacate and agreement renewals are
 * deliberately kept distinct throughout: only decide('approve_extension')
 * ever touches the Contract/ContractService versioning mechanism.
 */
class TenancyNoticeService
{
    public function issue(
        Tenancy $tenancy,
        int $issuedBy,
        string $reasonCategory,
        ?string $reasonDetails,
        string $noticeDate,
        ?int $noticePeriodDays = null,
        ?UploadedFile $evidence = null,
    ): TenancyNotice {
        if (! in_array($tenancy->fresh()->status, Tenancy::OCCUPYING_STATUSES, true)) {
            throw new InvalidArgumentException('This tenancy has already been ended.');
        }

        if (! in_array($reasonCategory, TenancyNotice::REASON_CATEGORIES, true)) {
            throw new InvalidArgumentException('Invalid notice reason.');
        }

        $active = $tenancy->activeNotice();

        $periodDays = $noticePeriodDays ?? $tenancy->notice_period_days;
        $proposedVacateDate = $periodDays
            ? Carbon::parse($noticeDate)->addDays($periodDays)->format('Y-m-d')
            : null;

        $notice = TenancyNotice::create([
            'tenancy_id' => $tenancy->id,
            'contract_id' => $tenancy->contract?->id,
            'sequence_number' => $tenancy->noticeCount() + 1,
            'issued_by' => $issuedBy,
            'reason_category' => $reasonCategory,
            'reason_details' => $reasonDetails,
            'notice_date' => $noticeDate,
            'notice_period_days' => $periodDays,
            'proposed_vacate_date' => $proposedVacateDate,
            'status' => 'issued',
        ]);

        if ($active) {
            $active->update(['status' => 'superseded', 'superseded_at' => now(), 'superseded_by_notice_id' => $notice->id]);
            $this->logEvent($active, 'superseded', causedBy: $issuedBy);
        }

        $this->logEvent($notice, 'issued', causedBy: $issuedBy);

        $tenancy->update(['status' => 'notice_given', 'notice_date' => $noticeDate]);

        if ($evidence) {
            $path = $evidence->store('documents', 'local');

            Document::create([
                'documentable_type' => $notice->getMorphClass(),
                'documentable_id' => $notice->id,
                'category' => 'tenancy_notice_evidence',
                'original_filename' => $evidence->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $evidence->getMimeType(),
                'size' => $evidence->getSize(),
                'uploaded_by' => $issuedBy,
            ]);
        }

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'tenancy_notice_issued',
            'You Have Received a Tenancy Notice',
            'Your landlord has issued a notice regarding your tenancy. Proposed move-out date: '
                .($proposedVacateDate ? Carbon::parse($proposedVacateDate)->format('d M Y') : 'not specified').'.',
            ['in_app', 'email'],
            $notice,
        );

        return $notice;
    }

    public function acknowledge(TenancyNotice $notice, int $tenantId): void
    {
        if ($notice->acknowledged_at) {
            return;
        }

        $notice->update([
            'acknowledged_at' => now(),
            'status' => $notice->status === 'issued' ? 'acknowledged' : $notice->status,
        ]);

        $this->logEvent($notice, 'acknowledged', tenantId: $tenantId);

        app(NotificationService::class)->notify(
            $this->landlordFor($notice),
            'tenancy_notice_acknowledged',
            'Tenant Acknowledged a Tenancy Notice',
            $notice->tenancy->tenant->name.' acknowledged receipt of their tenancy notice.',
            ['in_app'],
            $notice,
        );
    }

    /**
     * Acknowledging receipt is never treated as agreeing with the notice —
     * the tenant's actual position is one of these three, always
     * distinguishable from each other in both the status machine's
     * tenant_response_type column and in every notification/UI label.
     */
    public function respond(
        TenancyNotice $notice,
        int $tenantId,
        string $responseType,
        ?string $note,
        ?string $requestedNewVacateDate = null,
    ): void {
        if (! in_array($responseType, TenancyNotice::RESPONSE_TYPES, true)) {
            throw new InvalidArgumentException('Invalid response type.');
        }

        if (! $notice->isOpen()) {
            throw new InvalidArgumentException('This notice is no longer open for a response.');
        }

        if ($responseType === 'request_time') {
            if (! $requestedNewVacateDate) {
                throw new InvalidArgumentException('Please specify the date you are requesting.');
            }

            $proposed = $notice->proposed_vacate_date;

            if ($proposed && Carbon::parse($requestedNewVacateDate)->lte($proposed)) {
                throw new InvalidArgumentException('That date is not later than the move-out date already given.');
            }
        }

        if (! $notice->acknowledged_at) {
            $notice->update(['acknowledged_at' => now()]);
            $this->logEvent($notice, 'acknowledged', tenantId: $tenantId);
        }

        $notice->update([
            'status' => 'under_review',
            'tenant_response_type' => $responseType,
            'tenant_response_note' => $note,
            'requested_new_vacate_date' => $responseType === 'request_time' ? $requestedNewVacateDate : null,
            // A second round starts clean — any prior round's decision must
            // not look like it still applies to this new response.
            'decision' => null,
            'decision_note' => null,
            'decided_by' => null,
            'decided_at' => null,
        ]);

        $this->logEvent($notice, $responseType, tenantId: $tenantId, note: $note, newVacateDate: $responseType === 'request_time' ? $requestedNewVacateDate : null);

        $eventType = match ($responseType) {
            'dispute' => 'tenancy_notice_dispute_submitted',
            'request_time' => 'tenancy_notice_time_requested',
            'request_extension' => 'tenancy_notice_extension_requested',
        };

        $title = match ($responseType) {
            'dispute' => 'Tenant Disputed a Tenancy Notice',
            'request_time' => 'Tenant Requested More Time to Vacate',
            'request_extension' => 'Tenant Requested an Agreement Extension',
        };

        app(NotificationService::class)->notify(
            $this->landlordFor($notice),
            $eventType,
            $title,
            $notice->tenancy->tenant->name.' responded to their tenancy notice.'.($note ? " \"{$note}\"" : ''),
            ['in_app', 'email'],
            $notice,
        );
    }

    /**
     * The landlord's decision on whatever the tenant actually asked for.
     * approve_extension is the only path that touches ContractService — an
     * extension of time to vacate and a renewal of the agreement itself are
     * never the same action.
     */
    public function decide(
        TenancyNotice $notice,
        int $decidedBy,
        string $decision,
        ?string $decisionNote = null,
        ?string $newLeaseEndDate = null,
    ): TenancyNotice {
        if (! in_array($decision, TenancyNotice::DECISIONS, true)) {
            throw new InvalidArgumentException('Invalid decision.');
        }

        if ($notice->status !== 'under_review') {
            throw new InvalidArgumentException('There is no pending response to decide on.');
        }

        if ($decision === 'approve_time' && $notice->tenant_response_type !== 'request_time') {
            throw new InvalidArgumentException('This decision does not match what the tenant asked for.');
        }

        if ($decision === 'approve_extension' && $notice->tenant_response_type !== 'request_extension') {
            throw new InvalidArgumentException('This decision does not match what the tenant asked for.');
        }

        $tenancy = $notice->tenancy;

        $update = [
            'status' => 'resolved',
            'decision' => $decision,
            'decision_note' => $decisionNote,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
        ];

        if ($decision === 'approve_time') {
            $update['final_vacate_date'] = $notice->requested_new_vacate_date;
        } else {
            $update['final_vacate_date'] ??= $notice->proposed_vacate_date;
        }

        if ($decision === 'approve_extension') {
            if (! $newLeaseEndDate) {
                throw new InvalidArgumentException('Please specify the new agreement end date.');
            }

            try {
                $newContract = app(ContractService::class)->generateForTenancy($tenancy);
            } catch (RuntimeException $e) {
                throw new InvalidArgumentException('No default contract template is configured — generate or upload a renewed agreement manually before approving.');
            }

            $update['new_contract_id'] = $newContract->id;

            $tenancy->update(['status' => 'active', 'lease_end_date' => $newLeaseEndDate]);
        }

        $notice->update($update);

        $eventType = match ($decision) {
            'approve_time' => 'decision_approve_time',
            'approve_extension' => 'decision_approve_extension',
            'decline' => 'decision_decline',
        };

        $this->logEvent($notice, $eventType, causedBy: $decidedBy, note: $decisionNote);

        // The landlord just acted on whatever alert (dispute/time/extension
        // request) brought them here — that alert no longer needs attention.
        app(NotificationService::class)->markRelatedRead($notice, $this->landlordFor($notice));

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'tenancy_notice_decision_made',
            match ($decision) {
                'approve_time' => 'Your Request for More Time Was Approved',
                'approve_extension' => 'Your Tenancy Agreement Has Been Renewed',
                'decline' => 'Your Tenancy Notice Response Was Reviewed',
            },
            match ($decision) {
                'approve_time' => 'Your landlord approved your request — your new move-out date is '.Carbon::parse($notice->final_vacate_date)->format('d M Y').'.',
                'approve_extension' => 'Your landlord approved renewing your tenancy agreement. A new agreement is ready for your review.',
                'decline' => 'Your landlord reviewed your response and the original notice stands.'.($decisionNote ? " \"{$decisionNote}\"" : ''),
            },
            ['in_app', 'email'],
            $notice,
        );

        return $notice->refresh();
    }

    public function withdraw(TenancyNotice $notice, int $withdrawnBy, ?string $reason = null): void
    {
        if (! $notice->isOpen()) {
            throw new InvalidArgumentException('This notice is no longer open.');
        }

        $tenancy = $notice->tenancy;

        $notice->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
            'withdrawn_by' => $withdrawnBy,
            'withdrawn_reason' => $reason,
        ]);

        $this->logEvent($notice, 'withdrawn', causedBy: $withdrawnBy, note: $reason);

        if ($tenancy->status === 'notice_given' && $tenancy->latestNotice()?->id === $notice->id) {
            $tenancy->update(['status' => 'active']);
        }

        // Withdrawing instead of deciding is still the landlord acting on
        // whatever response the tenant gave, if any — clear it the same way.
        app(NotificationService::class)->markRelatedRead($notice, $this->landlordFor($notice));

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'tenancy_notice_withdrawn',
            'A Tenancy Notice Was Withdrawn',
            'Your landlord has withdrawn the tenancy notice issued on '.$notice->notice_date->format('d M Y').'.',
            ['in_app', 'email'],
            $notice,
        );
    }

    /** Every still-open notice on a tenancy closes out once it's formally terminated — no notification of its own, the overarching tenancy_ended notice already covers it. */
    public function closeForTermination(Tenancy $tenancy, int $closedBy): void
    {
        foreach ($tenancy->notices()->open()->get() as $notice) {
            $notice->update(['status' => 'closed', 'closed_at' => now()]);
            $this->logEvent($notice, 'closed', causedBy: $closedBy);
        }
    }

    private function logEvent(
        TenancyNotice $notice,
        string $eventType,
        ?int $tenantId = null,
        ?int $causedBy = null,
        ?string $note = null,
        ?string $newVacateDate = null,
    ): void {
        TenancyNoticeEvent::create([
            'tenancy_notice_id' => $notice->id,
            'event_type' => $eventType,
            'tenant_id' => $tenantId,
            'caused_by' => $causedBy,
            'note' => $note,
            'new_vacate_date' => $newVacateDate,
        ]);
    }

    private function landlordFor(TenancyNotice $notice)
    {
        return $notice->tenancy->house->block->property->landlord;
    }
}
