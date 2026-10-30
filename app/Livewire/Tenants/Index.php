<?php

namespace App\Livewire\Tenants;

use App\Models\Block;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public string $status = 'all';

    #[Url]
    public int $perPage = 25;

    // TenantPolicy::viewAny() passes for any manager — the landlord-granted
    // tenants.view permission is the real gate, same as Contracts/Arrears.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasManagerPermission('tenants.view'), 403);
    }

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->resetPage();
    }

    public function delete(Tenant $tenant): void
    {
        $this->authorize('delete', $tenant);

        if ($tenant->tenancies()->whereIn('status', Tenancy::OCCUPYING_STATUSES)->exists()) {
            session()->flash('status', 'This tenant has an active tenancy — end the tenancy before deleting their record.');

            return;
        }

        $tenant->delete();
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

        // Which tenancy represents each tenant's row in the table: whatever
        // status was picked, or their currently-active one by default.
        $displayScope = fn ($q) => $this->status !== 'all' ? $q->where('status', $this->status) : $q->active();

        $query = Tenant::with(['tenancies' => fn ($q) => $displayScope($q)->with('house.block.property')->latest('move_in_date')])
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")))
            ->when($this->status !== 'all', fn ($q) => $q->whereHas('tenancies', fn ($q2) => $q2->where('status', $this->status)))
            ->when($this->propertyId !== 'all', fn ($q) => $q->whereHas(
                'tenancies.house.block',
                fn ($q2) => $q2->where('property_id', $this->propertyId)
            ))
            ->when($this->blockId !== 'all', fn ($q) => $q->whereHas(
                'tenancies.house',
                fn ($q2) => $q2->where('block_id', $this->blockId)
            ))
            ->latest()
            ->accessibleBy($user);

        return view('livewire.tenants.index', [
            'tenants' => $query->paginate($this->perPage),
            'properties' => $properties,
            'blocks' => $blocks,
        ]);
    }
}
