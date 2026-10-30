<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Tenancy;
use App\Models\TenancyChangeRequest;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * A tenant asking for something about their tenancy to change. Accepting
 * one never touches the Tenancy row itself — the landlord still has to make
 * the actual change through whatever tool already handles it (editing the
 * tenancy, recording a deposit adjustment, etc.) and document it. This is
 * deliberately simpler than DueDayChangeRequest, which does auto-apply —
 * the user's instruction for this workflow is explicitly stricter.
 */
class TenancyChangeRequestService
{
    public function create(
        Tenancy $tenancy,
        int $tenantId,
        string $category,
        string $requestedChange,
        ?string $reason,
        ?UploadedFile $evidence = null,
    ): TenancyChangeRequest {
        if (! in_array($category, TenancyChangeRequest::CATEGORIES, true)) {
            throw new InvalidArgumentException('Invalid request category.');
        }

        $request = TenancyChangeRequest::create([
            'tenancy_id' => $tenancy->id,
            'category' => $category,
            'current_value' => $this->snapshotCurrentValue($tenancy, $category),
            'requested_change' => $requestedChange,
            'reason' => $reason,
            'status' => 'pending',
        ]);

        if ($evidence) {
            $path = $evidence->store('documents', 'local');

            Document::create([
                'documentable_type' => $request->getMorphClass(),
                'documentable_id' => $request->id,
                'category' => 'change_request_evidence',
                'original_filename' => $evidence->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $evidence->getMimeType(),
                'size' => $evidence->getSize(),
                'uploaded_by' => $tenantId,
            ]);
        }

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'tenancy_change_request_submitted',
            'Tenant Requested a Tenancy Change',
            $tenancy->tenant->name.' requested a change to '.$this->categoryLabel($category).': '.$requestedChange,
            ['in_app', 'email'],
            $request,
        );

        return $request;
    }

    public function markUnderReview(TenancyChangeRequest $request, int $byUserId): void
    {
        if (! $request->isOpen()) {
            throw new InvalidArgumentException('This request is no longer open.');
        }

        $request->update(['status' => 'under_review']);
    }

    public function decide(TenancyChangeRequest $request, int $decidedBy, string $decision, ?string $note = null): void
    {
        if (! in_array($decision, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid decision.');
        }

        if (! $request->isOpen()) {
            throw new InvalidArgumentException('This request has already been decided.');
        }

        $request->update([
            'status' => $decision,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        app(NotificationService::class)->markRelatedRead($request, $request->tenancy->house->block->property->landlord);

        app(NotificationService::class)->notify(
            $request->tenancy->tenant,
            'tenancy_change_request_decided',
            $decision === 'accepted' ? 'Your Change Request Was Accepted' : 'Your Change Request Was Declined',
            $decision === 'accepted'
                ? 'Your landlord accepted your request — they\'ll follow up with the actual change.'.($note ? " \"{$note}\"" : '')
                : 'Your landlord declined your request.'.($note ? " \"{$note}\"" : ''),
            ['in_app', 'email'],
            $request,
        );
    }

    private function snapshotCurrentValue(Tenancy $tenancy, string $category): ?string
    {
        return match ($category) {
            'monthly_rent' => 'KSh '.number_format((float) $tenancy->monthly_rent, 2),
            'rent_due_date' => Tenancy::ordinalDay($tenancy->dueDay()).' of each month',
            'security_deposit' => 'KSh '.number_format((float) $tenancy->deposit_required, 2),
            'tenancy_agreement' => $tenancy->lease_end_date ? 'Ends '.$tenancy->lease_end_date->format('d M Y') : 'Open-ended',
            default => null,
        };
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'monthly_rent' => 'monthly rent',
            'rent_due_date' => 'rent due date',
            'security_deposit' => 'security deposit',
            'tenancy_agreement' => 'tenancy agreement details',
            'property_unit_details' => 'property/unit details',
            default => 'something else',
        };
    }
}
