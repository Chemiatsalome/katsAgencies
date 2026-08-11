<?php

namespace App\Livewire\Tenancies;

use App\Models\Block;
use App\Models\House;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Services\TenancyProvisioningService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?int $property_id = null;

    public ?int $block_id = null;

    public ?int $house_id = null;

    public ?int $tenant_id = null;

    public bool $newTenant = false;

    public string $new_tenant_name = '';

    public string $new_tenant_phone = '';

    public int $new_tenant_payment_due_day = 1;

    public string $move_in_date;

    public string $lease_end_date = '';

    public string $notice_period_days = '';

    public string $monthly_rent = '';

    public string $deposit_required = '';

    public string $initial_deposit_paid = '';

    public string $deposit_method = 'cash';

    public int $rent_due_day = 1;

    public function mount(): void
    {
        $this->authorize('create', Tenancy::class);
        $this->move_in_date = now()->format('Y-m-d');

        if (request()->query('house_id')) {
            $house = House::with('block')->find((int) request()->query('house_id'));
            if ($house) {
                $this->block_id = $house->block_id;
                $this->property_id = $house->block->property_id;
                $this->house_id = $house->id;
                $this->syncRentFromHouse();
            }
        }
    }

    public function updatedPropertyId(): void
    {
        $this->block_id = null;
        $this->house_id = null;
    }

    public function updatedBlockId(): void
    {
        $this->house_id = null;
    }

    public function updatedHouseId(): void
    {
        $this->syncRentFromHouse();
    }

    public function updatedTenantId(): void
    {
        $tenant = Tenant::find($this->tenant_id);
        if ($tenant) {
            $this->rent_due_day = $tenant->payment_due_day;
        }
    }

    private function syncRentFromHouse(): void
    {
        $house = House::find($this->house_id);
        if ($house) {
            $this->monthly_rent = (string) $house->monthly_rent;
            $this->deposit_required = (string) $house->deposit_amount;
        }
    }

    public function save(): void
    {
        $rules = [
            'property_id' => ['required', 'exists:properties,id'],
            'block_id' => ['required', 'exists:blocks,id'],
            'house_id' => ['required', 'exists:houses,id'],
            'move_in_date' => ['required', 'date'],
            'lease_end_date' => ['nullable', 'date', 'after:move_in_date'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'deposit_required' => ['required', 'numeric', 'min:0'],
            'initial_deposit_paid' => ['nullable', 'numeric', 'min:0'],
            'rent_due_day' => ['required', 'integer', 'min:1', 'max:31'],
        ];

        if ($this->newTenant) {
            $rules['new_tenant_name'] = ['required', 'string', 'max:255'];
            $rules['new_tenant_phone'] = ['required', 'string', 'max:32'];
            $rules['new_tenant_payment_due_day'] = ['required', 'integer', 'min:1', 'max:31'];
        } else {
            $rules['tenant_id'] = ['required', 'exists:tenants,id'];
        }

        $this->validate($rules);

        $house = House::findOrFail($this->house_id);
        $this->authorize('update', $house);

        $tenancyData = [
            'house_id' => $house->id,
            'move_in_date' => $this->move_in_date,
            'lease_end_date' => $this->lease_end_date ?: null,
            'notice_period_days' => $this->notice_period_days !== '' ? (int) $this->notice_period_days : null,
            'monthly_rent' => $this->monthly_rent,
            'deposit_required' => $this->deposit_required,
            'rent_due_day' => $this->rent_due_day,
            'initial_deposit_paid' => $this->initial_deposit_paid,
            'deposit_method' => $this->deposit_method,
        ];

        $provisioning = app(TenancyProvisioningService::class);

        if ($this->newTenant) {
            $tenancy = $provisioning->createTenantAndTenancy([
                'name' => $this->new_tenant_name,
                'phone' => $this->new_tenant_phone,
                'payment_due_day' => $this->new_tenant_payment_due_day,
            ], $tenancyData);
        } else {
            $tenant = Tenant::findOrFail($this->tenant_id);
            $tenancy = $provisioning->createTenancyForExistingTenant($tenant, $tenancyData);
        }

        session()->flash('status', 'Tenant moved in.');
        $this->redirectRoute('manage.tenancies.show', $tenancy, navigate: true);
    }

    public function render()
    {
        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');

        $propertiesQuery = $isAdmin ? Property::query() : Property::forLandlord($user->id);
        $properties = $propertiesQuery->orderBy('name')->get(['id', 'name']);

        $blocks = collect();
        if ($this->property_id) {
            $blocks = Block::where('property_id', $this->property_id)->orderBy('name')->get(['id', 'name']);
        }

        $housesQuery = House::vacant()->with('block.property');
        if ($this->block_id) {
            $housesQuery->where('block_id', $this->block_id);
        } elseif ($this->property_id) {
            $housesQuery->whereHas('block', fn ($q) => $q->where('property_id', $this->property_id));
        }
        if (! $isAdmin) {
            $housesQuery->whereHas('block.property', fn ($q) => $q->where('landlord_id', $user->id));
        }

        $tenantsQuery = Tenant::orderBy('name');

        return view('livewire.tenancies.form', [
            'properties' => $properties,
            'blocks' => $blocks,
            'availableHouses' => $housesQuery->get(),
            'availableTenants' => $tenantsQuery->get(),
        ]);
    }
}
