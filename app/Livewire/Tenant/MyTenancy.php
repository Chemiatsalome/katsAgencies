<?php

namespace App\Livewire\Tenant;

use App\Models\TenancyChangeRequest;
use App\Models\TenancyNotice;
use App\Models\TenancyRenewalRequest;
use App\Services\TenancyChangeRequestService;
use App\Services\TenancyNoticeService;
use App\Services\TenancyRenewalService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.tenant')]
class MyTenancy extends Component
{
    use WithFileUploads;

    public bool $showRequestChangeForm = false;

    public string $change_category = 'other';

    public string $change_requested_change = '';

    public string $change_reason = '';

    public $change_evidence;

    public bool $showRenewalForm = false;

    public string $renewal_duration_months = '12';

    public string $renewal_preferred_end_date = '';

    public string $renewal_message = '';

    public bool $showDisputeForm = false;

    public string $dispute_note = '';

    public bool $showRequestTimeForm = false;

    public string $requested_new_vacate_date = '';

    public string $request_time_note = '';

    public bool $showRequestExtensionForm = false;

    public string $request_extension_note = '';

    private function currentTenancy()
    {
        $tenancy = Auth::user()->tenant?->currentTenancy();
        $tenancy?->load('house.block.property');
        abort_if(! $tenancy, 404);

        return $tenancy;
    }

    public function openRequestChangeForm(): void
    {
        $this->change_category = 'other';
        $this->change_requested_change = '';
        $this->change_reason = '';
        $this->change_evidence = null;
        $this->showRequestChangeForm = true;
    }

    public function cancelRequestChangeForm(): void
    {
        $this->reset(['change_category', 'change_requested_change', 'change_reason', 'change_evidence', 'showRequestChangeForm']);
        $this->change_category = 'other';
    }

    public function submitChangeRequest(): void
    {
        $tenancy = $this->currentTenancy();

        $data = $this->validate([
            'change_category' => ['required', 'in:'.implode(',', TenancyChangeRequest::CATEGORIES)],
            'change_requested_change' => ['required', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
            'change_evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        app(TenancyChangeRequestService::class)->create(
            $tenancy,
            Auth::user()->tenant->id,
            $data['change_category'],
            $data['change_requested_change'],
            $data['change_reason'] ?: null,
            $this->change_evidence ?: null,
        );

        $this->cancelRequestChangeForm();
        session()->flash('status', 'Your request has been sent to your landlord.');
    }

    public function openRenewalForm(): void
    {
        $this->renewal_duration_months = '12';
        $this->renewal_preferred_end_date = '';
        $this->renewal_message = '';
        $this->showRenewalForm = true;
    }

    public function cancelRenewalForm(): void
    {
        $this->reset(['renewal_preferred_end_date', 'renewal_message', 'showRenewalForm']);
        $this->renewal_duration_months = '12';
    }

    public function submitRenewalRequest(): void
    {
        $tenancy = $this->currentTenancy();

        $data = $this->validate([
            'renewal_duration_months' => ['required', 'in:12,24'],
            'renewal_preferred_end_date' => ['nullable', 'date', 'after:today'],
            'renewal_message' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(TenancyRenewalService::class)->request(
                $tenancy,
                Auth::user()->tenant->id,
                (int) $data['renewal_duration_months'],
                $data['renewal_preferred_end_date'] ?: null,
                $data['renewal_message'] ?: null,
            );
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelRenewalForm();
        session()->flash('status', 'Your renewal request has been sent to your landlord.');
    }

    /** Only ever act on a renewal request against the current tenant's own tenancy. */
    private function ownRenewalRequest(int $requestId): TenancyRenewalRequest
    {
        $tenant = Auth::user()->tenant;

        return TenancyRenewalRequest::whereHas('tenancy', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->findOrFail($requestId);
    }

    public function respondToRenewalAlternative(int $requestId, bool $accept): void
    {
        $request = $this->ownRenewalRequest($requestId);

        try {
            app(TenancyRenewalService::class)->respondToAlternative($request, Auth::user()->tenant->id, $accept);
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', $accept ? 'You accepted the new terms — your agreement has been renewed.' : 'You declined the proposed terms.');
    }

    /** Only ever act on a notice against the current tenant's own tenancy. */
    private function ownNotice(int $noticeId): TenancyNotice
    {
        $tenant = Auth::user()->tenant;

        return TenancyNotice::whereHas('tenancy', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->findOrFail($noticeId);
    }

    public function acknowledgeNotice(int $noticeId): void
    {
        $notice = $this->ownNotice($noticeId);

        app(TenancyNoticeService::class)->acknowledge($notice, Auth::user()->tenant->id);

        session()->flash('status', 'Acknowledged.');
    }

    public function openDisputeForm(): void
    {
        $this->dispute_note = '';
        $this->showDisputeForm = true;
    }

    public function cancelDisputeForm(): void
    {
        $this->reset(['dispute_note', 'showDisputeForm']);
    }

    public function submitDispute(int $noticeId): void
    {
        $notice = $this->ownNotice($noticeId);

        $this->validate(['dispute_note' => ['required', 'string', 'max:2000']]);

        try {
            app(TenancyNoticeService::class)->respond($notice, Auth::user()->tenant->id, 'dispute', $this->dispute_note);
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelDisputeForm();
        session()->flash('status', 'Your dispute has been sent to your landlord.');
    }

    public function openRequestTimeForm(): void
    {
        $this->requested_new_vacate_date = '';
        $this->request_time_note = '';
        $this->showRequestTimeForm = true;
    }

    public function cancelRequestTimeForm(): void
    {
        $this->reset(['requested_new_vacate_date', 'request_time_note', 'showRequestTimeForm']);
    }

    public function submitRequestTime(int $noticeId): void
    {
        $notice = $this->ownNotice($noticeId);

        $this->validate([
            'requested_new_vacate_date' => ['required', 'date', 'after:today'],
            'request_time_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(TenancyNoticeService::class)->respond(
                $notice, Auth::user()->tenant->id, 'request_time', $this->request_time_note ?: null, $this->requested_new_vacate_date,
            );
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelRequestTimeForm();
        session()->flash('status', 'Your request has been sent to your landlord.');
    }

    public function openRequestExtensionForm(): void
    {
        $this->request_extension_note = '';
        $this->showRequestExtensionForm = true;
    }

    public function cancelRequestExtensionForm(): void
    {
        $this->reset(['request_extension_note', 'showRequestExtensionForm']);
    }

    public function submitRequestExtension(int $noticeId): void
    {
        $notice = $this->ownNotice($noticeId);

        $this->validate(['request_extension_note' => ['nullable', 'string', 'max:2000']]);

        try {
            app(TenancyNoticeService::class)->respond(
                $notice, Auth::user()->tenant->id, 'request_extension', $this->request_extension_note ?: null,
            );
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelRequestExtensionForm();
        session()->flash('status', 'Your request has been sent to your landlord.');
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $currentTenancy = $tenant?->currentTenancy();
        $currentTenancy?->load('house.block.property');

        $pastTenancies = $tenant
            ? $tenant->tenancies()->with('house.block.property')
                ->when($currentTenancy, fn ($q) => $q->where('id', '!=', $currentTenancy->id))
                ->orderByDesc('move_in_date')
                ->get()
            : collect();

        // Everything the landlord has uploaded against this tenancy except
        // the lease agreement itself — that one already has its own
        // dedicated, version-aware list on the Contract page. Without this,
        // anything uploaded through the generic Documents widget on the
        // landlord's Tenancy page (an ID request, a notice, whatever) was
        // invisible to the tenant it actually concerned.
        $documents = $currentTenancy
            ? $currentTenancy->documents()->where('category', '!=', 'lease_agreement')->latest()->get()
            : collect();

        return view('livewire.tenant.my-tenancy', [
            'tenancy' => $currentTenancy,
            'pastTenancies' => $pastTenancies,
            'documents' => $documents,
            'categoryLabels' => \App\Livewire\Documents\Manager::CATEGORIES,
            'activeNotice' => $currentTenancy?->activeNotice(),
            'noticeHistory' => $currentTenancy ? $currentTenancy->notices()->with('events')->orderByDesc('sequence_number')->get() : collect(),
            'changeRequestHistory' => $currentTenancy ? $currentTenancy->changeRequests()->latest()->get() : collect(),
            'canRenew' => (bool) $currentTenancy?->renewalRelevant(),
            'activeRenewalRequest' => $currentTenancy?->activeRenewalRequest(),
            'renewalHistory' => $currentTenancy ? $currentTenancy->renewalRequests()->latest()->get() : collect(),
        ]);
    }
}
