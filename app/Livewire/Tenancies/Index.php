<?php

namespace App\Livewire\Tenancies;

use App\Models\Block;
use App\Models\Property;
use App\Models\Tenancy;
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
    public string $status = 'active';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public int $perPage = 25;

    public ?int $renewingTenancyId = null;

    public string $renew_lease_end_date = '';

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->resetPage();
    }

    public function openRenew(int $tenancyId): void
    {
        $tenancy = Tenancy::findOrFail($tenancyId);
        $this->authorize('update', $tenancy);

        $this->renewingTenancyId = $tenancyId;
        $this->renew_lease_end_date = $tenancy->lease_end_date?->addYear()->format('Y-m-d')
            ?? now()->addYear()->format('Y-m-d');
    }

    public function cancelRenew(): void
    {
        $this->reset(['renewingTenancyId', 'renew_lease_end_date']);
    }

    public function renew(): void
    {
        $tenancy = Tenancy::findOrFail($this->renewingTenancyId);
        $this->authorize('update', $tenancy);

        $this->validate([
            'renew_lease_end_date' => ['required', 'date', 'after:today'],
        ]);

        $tenancy->update([
            'lease_end_date' => $this->renew_lease_end_date,
            'status' => 'active',
        ]);

        $this->cancelRenew();
        session()->flash('status', 'Tenancy renewed.');
    }

    public function render()
    {
        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');

        $propertiesQuery = $isAdmin ? Property::query() : Property::forLandlord($user->id);
        $properties = $propertiesQuery->orderBy('name')->get(['id', 'name']);

        $blocksQuery = Block::query()->when(
            $this->propertyId !== 'all',
            fn ($q) => $q->where('property_id', $this->propertyId)
        );
        if (! $isAdmin) {
            $blocksQuery->whereHas('property', fn ($q) => $q->where('landlord_id', $user->id));
        }
        $blocks = $blocksQuery->orderBy('name')->get(['id', 'name', 'property_id']);

        $query = Tenancy::with(['tenant', 'house.block.property'])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->propertyId !== 'all', fn ($q) => $q->whereHas(
                'house.block',
                fn ($q2) => $q2->where('property_id', $this->propertyId)
            ))
            ->when($this->blockId !== 'all', fn ($q) => $q->whereHas(
                'house',
                fn ($q2) => $q2->where('block_id', $this->blockId)
            ))
            ->latest();

        if (! $isAdmin) {
            $query->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $user->id));
        }

        $expiringSoonQuery = Tenancy::expiringSoon(30)->with(['tenant', 'house.block.property']);
        if (! $isAdmin) {
            $expiringSoonQuery->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $user->id));
        }

        return view('livewire.tenancies.index', [
            'tenancies' => $query->paginate($this->perPage),
            'properties' => $properties,
            'blocks' => $blocks,
            'expiringSoon' => $expiringSoonQuery->orderBy('lease_end_date')->get(),
        ]);
    }
}
