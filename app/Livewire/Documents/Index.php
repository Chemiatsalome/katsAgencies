<?php

namespace App\Livewire\Documents;

use App\Models\Block;
use App\Models\Complaint;
use App\Models\Document;
use App\Models\Expense;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $category = 'all';

    #[Url]
    public string $contractStatus = 'all';

    #[Url]
    public string $tenantId = 'all';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public string $houseId = 'all';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $perPage = 25;

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->houseId = 'all';
    }

    public function updatedBlockId(): void
    {
        $this->houseId = 'all';
    }

    public function delete(int $documentId): void
    {
        $document = Document::findOrFail($documentId);
        $this->authorize('delete', $document);

        // A lease agreement IS the contract record (Contract::document_id
        // points back at it) — deleting the file out from under a sent or
        // signed contract would silently break that tenancy's audit trail
        // and leave a broken download link. Superseding it means generating
        // a new version from the Tenancy page, not deleting this one. The
        // Document model itself also refuses this (see Document::booted());
        // this check just gives a clear reason instead of a silent no-op.
        if ($document->contract()->exists()) {
            $this->addError('delete', 'Lease agreements can\'t be deleted — generate a new version from the tenancy page instead, which keeps this one on record.');

            return;
        }

        $document->delete();
    }

    /** A nudge for a tenant who's had their lease sitting unsigned — reuses the same notification a fresh "send" would fire, just worded as a follow-up. */
    public function remindToSign(int $documentId): void
    {
        $document = Document::with('contract.tenancy.house.block.property.landlord', 'contract.tenancy.tenant')->findOrFail($documentId);
        $contract = $document->contract;

        abort_unless($contract && $contract->effectiveStatus() === 'awaiting_signature', 404);

        $tenancy = $contract->tenancy;
        $this->authorize('update', $tenancy);

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'contract_sent',
            'Reminder: Your Tenancy Agreement Is Awaiting Your Signature',
            'Your landlord is reminding you to review and sign your tenancy agreement from your tenant portal.',
            ['in_app', 'email'],
            $tenancy,
        );

        session()->flash('status', "Reminder sent to {$tenancy->tenant->name}.");
    }

    public function render()
    {
        $user = Auth::user();

        // Expense-linked documents (receipts) stay out of a manager's view —
        // Expense has no manager-accessible scope at all (ExpensePolicy is
        // landlord-only), so a document attached to one shouldn't leak either.
        $types = [Property::class, House::class, Tenancy::class, Tenant::class, MaintenanceTask::class, Complaint::class];
        if (! $user->hasRole('manager')) {
            $types[] = Expense::class;
        }

        $propertyId = $this->propertyId;
        $blockId = $this->blockId;
        $houseId = $this->houseId;
        $tenantId = $this->tenantId;

        // Property/block/unit/tenant mean something different for every
        // documentable type (a House doc has no tenant at all; a Property
        // doc can never belong to one specific unit), so each type gets its
        // own translation of the same four filters rather than one generic
        // clause — a filter that a type simply can't satisfy excludes it,
        // it doesn't just pass through.
        $query = Document::whereHasMorph(
            'documentable',
            $types,
            function ($q, $type) use ($user, $propertyId, $blockId, $houseId, $tenantId) {
                match ($type) {
                    Property::class => $q->accessibleBy($user)
                        ->when($propertyId !== 'all', fn ($qq) => $qq->where('id', $propertyId))
                        ->when($blockId !== 'all' || $houseId !== 'all' || $tenantId !== 'all', fn ($qq) => $qq->whereRaw('1 = 0')),
                    House::class => $q->accessibleBy($user)
                        ->when($propertyId !== 'all', fn ($qq) => $qq->whereHas('block', fn ($b) => $b->where('property_id', $propertyId)))
                        ->when($blockId !== 'all', fn ($qq) => $qq->where('block_id', $blockId))
                        ->when($houseId !== 'all', fn ($qq) => $qq->where('id', $houseId))
                        ->when($tenantId !== 'all', fn ($qq) => $qq->whereRaw('1 = 0')),
                    Tenancy::class => $q->accessibleBy($user)
                        ->when($propertyId !== 'all', fn ($qq) => $qq->whereHas('house.block', fn ($b) => $b->where('property_id', $propertyId)))
                        ->when($blockId !== 'all', fn ($qq) => $qq->whereHas('house', fn ($h) => $h->where('block_id', $blockId)))
                        ->when($houseId !== 'all', fn ($qq) => $qq->where('house_id', $houseId))
                        ->when($tenantId !== 'all', fn ($qq) => $qq->where('tenant_id', $tenantId)),
                    Tenant::class => $q->accessibleBy($user)
                        ->when($propertyId !== 'all', fn ($qq) => $qq->whereHas('tenancies.house.block', fn ($b) => $b->where('property_id', $propertyId)))
                        ->when($blockId !== 'all', fn ($qq) => $qq->whereHas('tenancies.house', fn ($h) => $h->where('block_id', $blockId)))
                        ->when($houseId !== 'all', fn ($qq) => $qq->whereHas('tenancies', fn ($t) => $t->where('house_id', $houseId)))
                        ->when($tenantId !== 'all', fn ($qq) => $qq->where('id', $tenantId)),
                    MaintenanceTask::class => $q->accessibleBy($user)
                        ->when($propertyId !== 'all', fn ($qq) => $qq->where('property_id', $propertyId))
                        ->when($blockId !== 'all', fn ($qq) => $qq->where('block_id', $blockId))
                        ->when($houseId !== 'all', fn ($qq) => $qq->where('house_id', $houseId))
                        ->when($tenantId !== 'all', fn ($qq) => $qq->whereRaw('1 = 0')),
                    Complaint::class => $q->accessibleBy($user)
                        ->when($propertyId !== 'all', fn ($qq) => $qq->where(fn ($w) => $w->where('property_id', $propertyId)->orWhereHas('house.block', fn ($b) => $b->where('property_id', $propertyId))))
                        ->when($blockId !== 'all', fn ($qq) => $qq->whereHas('house', fn ($h) => $h->where('block_id', $blockId)))
                        ->when($houseId !== 'all', fn ($qq) => $qq->where('house_id', $houseId))
                        ->when($tenantId !== 'all', fn ($qq) => $qq->where('tenant_id', $tenantId)),
                    Expense::class => $q->whereHas('property', fn ($q2) => $q2->accessibleBy($user))
                        ->when($propertyId !== 'all', fn ($qq) => $qq->where('property_id', $propertyId))
                        ->when($blockId !== 'all' || $houseId !== 'all' || $tenantId !== 'all', fn ($qq) => $qq->whereRaw('1 = 0')),
                    default => $q->whereRaw('1 = 0'),
                };
            }
        )->with('documentable', 'contract');

        if ($this->category !== 'all') {
            $query->where('category', $this->category);
        }

        if ($this->contractStatus !== 'all') {
            $query->when(
                $this->contractStatus === 'none',
                fn ($q) => $q->whereDoesntHave('contract'),
                fn ($q) => $q->whereHas('contract', fn ($cq) => $this->contractStatus === 'superseded'
                    ? $cq->whereNotNull('superseded_at')
                    : $cq->whereNull('superseded_at')->where('status', $this->contractStatus))
            );
        }

        if ($this->dateFrom !== '') {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        // Only meaningful next to the lease-agreement list — cheap enough
        // not to bother gating, and it answers exactly what was missing
        // before: which active tenants have signed and which haven't.
        $signatureCounts = Tenancy::accessibleBy($user)->active()->with('contract')->get()
            ->countBy(fn (Tenancy $t) => $t->contract?->status ?? 'none');

        return view('livewire.documents.index', [
            'documents' => $query->latest()->paginate($this->perPage),
            'categoryOptions' => \App\Livewire\Documents\Manager::CATEGORIES,
            'properties' => Property::accessibleBy($user)->orderBy('name')->get(['id', 'name']),
            'blocks' => Block::accessibleBy($user)->when($propertyId !== 'all', fn ($q) => $q->where('property_id', $propertyId))->orderBy('name')->get(['id', 'name', 'property_id']),
            'houses' => House::accessibleBy($user)
                ->when($propertyId !== 'all', fn ($q) => $q->whereHas('block', fn ($b) => $b->where('property_id', $propertyId)))
                ->when($blockId !== 'all', fn ($q) => $q->where('block_id', $blockId))
                ->orderBy('house_number')->get(['id', 'house_number', 'block_id']),
            'tenants' => Tenant::accessibleBy($user)->orderBy('name')->get(['id', 'name']),
            'signedCount' => $signatureCounts->get('signed', 0),
            'awaitingSignatureCount' => $signatureCounts->get('awaiting_signature', 0),
            'noContractCount' => $signatureCounts->get('none', 0) + $signatureCounts->get('draft', 0) + $signatureCounts->get('generated', 0),
        ]);
    }
}
