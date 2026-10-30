<?php

namespace App\Livewire\Tenant;

use App\Models\Document;
use App\Models\DueDayChangeRequest;
use App\Services\ContractService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.tenant')]
class Contract extends Component
{
    use WithFileUploads;

    public bool $agreedToTerms = false;

    public string $typedFullName = '';

    public $idDocument = null;

    public bool $showDueDayForm = false;

    public string $requestedDueDay = '';

    public string $dueDayReason = '';

    public bool $showDueDayHistory = false;

    public function toggleDueDayHistory(): void
    {
        $this->showDueDayHistory = ! $this->showDueDayHistory;
    }

    private function activeTenancy()
    {
        $tenancy = Auth::user()->tenant?->currentTenancy();
        $tenancy?->load('house.block.property');

        return $tenancy;
    }

    private function hasIdDocument(?\App\Models\Tenancy $tenancy): bool
    {
        return (bool) $tenancy?->documents()->where('category', 'id_document')->exists();
    }

    public function sign(): void
    {
        $tenancy = $this->activeTenancy();
        $contract = $tenancy?->contract;

        abort_unless($contract && $contract->isAwaitingSignature(), 403);

        $needsIdDocument = ! $this->hasIdDocument($tenancy);

        $this->validate([
            'agreedToTerms' => ['accepted'],
            'typedFullName' => ['required', 'string', 'min:2', 'max:255'],
            'idDocument' => [$needsIdDocument ? 'required' : 'nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'idDocument.required' => 'Please upload a copy of your ID or passport before signing.',
        ]);

        if ($this->idDocument) {
            $path = $this->idDocument->store('documents', 'local');

            Document::create([
                'documentable_type' => $tenancy->getMorphClass(),
                'documentable_id' => $tenancy->id,
                'category' => 'id_document',
                'original_filename' => $this->idDocument->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $this->idDocument->getMimeType(),
                'size' => $this->idDocument->getSize(),
                'uploaded_by' => Auth::id(),
            ]);
        }

        app(ContractService::class)->sign($contract, $this->typedFullName, request()->ip());

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'contract_signed',
            'Contract Signed',
            "{$tenancy->tenant->name} signed their lease agreement.",
            ['in_app'],
            $tenancy,
        );

        session()->flash('status', 'Contract signed. Thank you.');
    }

    public function cancelDueDayForm(): void
    {
        $this->reset(['showDueDayForm', 'requestedDueDay', 'dueDayReason']);
    }

    public function requestDueDayChange(): void
    {
        $tenancy = $this->activeTenancy();
        abort_unless($tenancy, 404);

        if ($tenancy->pendingDueDayChangeRequest()) {
            $this->addError('requestedDueDay', 'You already have a pending request.');

            return;
        }

        $this->validate([
            'requestedDueDay' => ['required', 'integer', 'min:1', 'max:31'],
            'dueDayReason' => ['nullable', 'string', 'max:1000'],
        ]);

        $dueDayRequest = DueDayChangeRequest::create([
            'tenancy_id' => $tenancy->id,
            'requested_due_day' => $this->requestedDueDay,
            'reason' => $this->dueDayReason ?: null,
            'status' => 'pending',
        ]);

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'due_day_change_requested',
            'Due Date Change Requested',
            "{$tenancy->tenant->name} is requesting to move their rent due date to the ".\App\Models\Tenancy::ordinalDay((int) $this->requestedDueDay).' of each month.'.($this->dueDayReason ? " Reason: {$this->dueDayReason}" : ''),
            ['in_app'],
            // Tied to this specific request, not the tenancy, so accepting
            // or rejecting it can mark exactly this notification read.
            $dueDayRequest,
        );

        $this->cancelDueDayForm();
        session()->flash('status', 'Your due date change request has been sent to your landlord. If approved, this date will no longer carry a grace period before a late fee applies.');
    }

    public function render()
    {
        $tenancy = $this->activeTenancy();
        $contract = $tenancy?->contract;

        $documents = $tenancy
            ? $tenancy->documents()->where('category', 'lease_agreement')->latest()->get()
            : collect();

        return view('livewire.tenant.contract', [
            'tenancy' => $tenancy,
            'contract' => $contract,
            'documents' => $documents,
            'hasIdDocument' => $this->hasIdDocument($tenancy),
            // Audit finding C7 — only the single latest decided request was
            // ever shown; older ones existed in the DB but were invisible.
            'dueDayHistory' => $tenancy ? $tenancy->dueDayChangeRequests()->whereNotNull('decided_at')->latest('decided_at')->get() : collect(),
        ]);
    }
}
