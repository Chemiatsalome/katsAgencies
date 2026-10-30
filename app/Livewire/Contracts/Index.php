<?php

namespace App\Livewire\Contracts;

use App\Models\Block;
use App\Models\Property;
use App\Models\Tenancy;
use App\Services\ContractService;
use App\Services\NotificationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public int $perPage = 25;

    public ?int $expandedTenancyId = null;

    // Matches the sidebar's gating for this page (tenancies.view) rather
    // than TenancyPolicy::viewAny(), which passes for any manager — the
    // landlord-granted permission is the real gate here, same as Arrears.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasManagerPermission('tenancies.view'), 403);
    }

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->resetPage();
    }

    public function toggleHistory(int $tenancyId): void
    {
        $this->expandedTenancyId = $this->expandedTenancyId === $tenancyId ? null : $tenancyId;
    }

    /**
     * Gated on tenancies.manage_contracts, not TenancyPolicy::update()'s
     * tenancies.terminate — the two used to be the same checkbox (no
     * dedicated contracts permission existed), which meant termination
     * rights silently also granted contract management. Still re-checks
     * property scoping the same way authorize('update', $tenancy) did.
     */
    private function authorizeContractManagement(Tenancy $tenancy): void
    {
        abort_unless(Tenancy::accessibleBy(Auth::user())->whereKey($tenancy->id)->exists(), 404);
        abort_unless(Auth::user()->hasRole('landlord') || Auth::user()->hasManagerPermission('tenancies.manage_contracts'), 403);
    }

    /** A nudge for a tenant who's had their contract sitting unsigned — same notification a fresh "send" fires, worded as a follow-up. */
    public function remind(int $tenancyId): void
    {
        $tenancy = Tenancy::with('contract', 'tenant', 'house.block.property.landlord')->findOrFail($tenancyId);
        $this->authorizeContractManagement($tenancy);

        $contract = $tenancy->contract;
        abort_unless($contract && $contract->effectiveStatus() === 'awaiting_signature', 404);

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

    public function sendContract(int $tenancyId): void
    {
        $tenancy = Tenancy::with('contract', 'tenant', 'house.block.property.landlord')->findOrFail($tenancyId);
        $this->authorizeContractManagement($tenancy);

        $contract = $tenancy->contract;
        abort_unless($contract && in_array($contract->status, ['draft', 'generated'], true), 404);

        app(ContractService::class)->markSent($contract);

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'contract_sent',
            'Your Tenancy Agreement Is Ready to Sign',
            'Your landlord has sent your tenancy agreement. Please review and sign it from your tenant portal.',
            ['in_app', 'email'],
            $tenancy,
        );

        session()->flash('status', "Contract sent to {$tenancy->tenant->name}.");
    }

    /** Issues a fresh version from the default template — only reachable once the landlord has explicitly confirmed via the wire:confirm prompt, same gate as the equivalent action on the Tenancy page. */
    public function generate(int $tenancyId): void
    {
        $tenancy = Tenancy::with('house.block.property')->findOrFail($tenancyId);
        $this->authorizeContractManagement($tenancy);

        try {
            app(ContractService::class)->generateForTenancy($tenancy);
            session()->flash('status', 'Contract generated.');
        } catch (\RuntimeException $e) {
            session()->flash('error', 'No active default contract template is configured. Set one up under Contract Templates first.');
        }
    }

    public function render()
    {
        $user = Auth::user();

        $properties = Property::accessibleBy($user)->orderBy('name')->get(['id', 'name']);

        $blocksQuery = Block::accessibleBy($user)->when(
            $this->propertyId !== 'all',
            fn ($q) => $q->where('property_id', $this->propertyId)
        );
        $blocks = $blocksQuery->orderBy('name')->get(['id', 'name', 'property_id']);

        $query = Tenancy::accessibleBy($user)
            ->with(['tenant', 'house.block.property', 'contract', 'contracts' => fn ($q) => $q->orderByDesc('version')])
            ->when($this->propertyId !== 'all', fn ($q) => $q->whereHas(
                'house.block',
                fn ($q2) => $q2->where('property_id', $this->propertyId)
            ))
            ->when($this->blockId !== 'all', fn ($q) => $q->whereHas(
                'house',
                fn ($q2) => $q2->where('block_id', $this->blockId)
            ))
            ->when($this->search, fn ($q) => $q->whereHas(
                'tenant',
                fn ($q2) => $q2->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")
            ))
            ->latest();

        $tenancies = $query->get();

        if ($this->status !== 'all') {
            $tenancies = $tenancies->filter(
                fn (Tenancy $t) => ($t->contract?->status ?? 'none') === $this->status
            )->values();
        }

        $page = $this->getPage();
        $paginated = new LengthAwarePaginator(
            $tenancies->forPage($page, $this->perPage)->values(),
            $tenancies->count(),
            $this->perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        // Counted off the full accessible set, not the status-filtered one
        // above — these tiles are meant to be stable reference points you
        // filter the list against, not numbers that vanish once you've
        // already filtered by one of them.
        $allTenancies = Tenancy::accessibleBy($user)->with('contract')->get();
        $statusCounts = $allTenancies->countBy(fn (Tenancy $t) => $t->contract?->status ?? 'none');

        return view('livewire.contracts.index', [
            'tenancies' => $paginated,
            'properties' => $properties,
            'blocks' => $blocks,
            'signedCount' => $statusCounts->get('signed', 0),
            'awaitingSignatureCount' => $statusCounts->get('awaiting_signature', 0) + $statusCounts->get('sent', 0),
            'noContractCount' => $statusCounts->get('none', 0),
        ]);
    }
}
