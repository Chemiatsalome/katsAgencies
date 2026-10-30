<?php

namespace App\Livewire\TenantInvitations;

use App\Models\Block;
use App\Models\House;
use App\Models\LandlordSetting;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Create extends Component
{
    public int $step = 1;

    public ?int $property_id = null;

    public ?int $block_id = null;

    public ?int $house_id = null;

    public bool $forExistingTenant = false;

    public ?int $existing_tenant_id = null;

    public string $monthly_rent = '';

    public string $deposit_required = '';

    public int $rent_due_day = 1;

    public string $move_in_date;

    public string $lease_end_date = '';

    public string $notice_period_days = '';

    public string $tenant_phone = '';

    public string $tenant_email = '';

    public ?TenantInvitation $invitation = null;

    public function mount(): void
    {
        $this->authorize('create', TenantInvitation::class);
        $this->move_in_date = now()->format('Y-m-d');

        $landlordId = Auth::user()->landlordId();
        $this->rent_due_day = $landlordId ? (int) LandlordSetting::get($landlordId, 'default_rent_due_day', 5) : 5;
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
        $house = House::find($this->house_id);
        if ($house) {
            $this->monthly_rent = (string) $house->monthly_rent;
            $this->deposit_required = (string) $house->deposit_amount;
        }
    }

    public function goToStep2(): void
    {
        $this->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'block_id' => ['required', 'exists:blocks,id'],
            'house_id' => ['required', 'exists:houses,id'],
        ]);

        $this->step = 2;
    }

    public function backToStep1(): void
    {
        $this->step = 1;
    }

    public function generate(): void
    {
        $rules = [
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'deposit_required' => ['required', 'numeric', 'min:0'],
            'rent_due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'move_in_date' => ['required', 'date'],
            'lease_end_date' => ['nullable', 'date', 'after:move_in_date'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tenant_phone' => ['nullable', 'string', 'max:32'],
            'tenant_email' => ['nullable', 'email', 'max:255'],
        ];

        if ($this->forExistingTenant) {
            $rules['existing_tenant_id'] = ['required', 'exists:tenants,id'];
        }

        $this->validate($rules);

        $house = House::findOrFail($this->house_id);
        $this->authorize('update', $house);

        if ($house->status !== 'vacant') {
            $this->addError('house_id', 'This unit is no longer available.');
            $this->step = 1;

            return;
        }

        $this->invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => Auth::id(),
            'existing_tenant_id' => $this->forExistingTenant ? $this->existing_tenant_id : null,
            'token' => TenantInvitation::generateToken(),
            'tenant_phone' => $this->tenant_phone ?: null,
            'tenant_email' => $this->tenant_email ?: null,
            'monthly_rent' => $this->monthly_rent,
            'deposit_required' => $this->deposit_required,
            'rent_due_day' => $this->rent_due_day,
            'move_in_date' => $this->move_in_date,
            'lease_end_date' => $this->lease_end_date ?: null,
            'notice_period_days' => $this->notice_period_days !== '' ? (int) $this->notice_period_days : null,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // Reserve the unit so it can't be double-booked by another invite while
        // this one is pending; TenancyObserver takes over (→ occupied) once the
        // tenant actually completes registration and a real Tenancy is created.
        $house->update(['status' => 'reserved']);

        $this->step = 3;
    }

    public function render()
    {
        $user = Auth::user();

        $properties = Property::accessibleBy($user)->orderBy('name')->get(['id', 'name']);

        $blocks = collect();
        if ($this->property_id) {
            $blocks = Block::where('property_id', $this->property_id)->accessibleBy($user)->orderBy('name')->get(['id', 'name']);
        }

        $housesQuery = House::vacant()->with('block.property')->accessibleBy($user);
        if ($this->block_id) {
            $housesQuery->where('block_id', $this->block_id);
        } elseif ($this->property_id) {
            $housesQuery->whereHas('block', fn ($q) => $q->where('property_id', $this->property_id));
        }

        return view('livewire.tenant-invitations.create', [
            'properties' => $properties,
            'blocks' => $blocks,
            'availableHouses' => $housesQuery->get(),
            'availableTenants' => Tenant::orderBy('name')->get(),
            'selectedHouse' => $this->house_id ? House::with('block.property')->find($this->house_id) : null,
        ]);
    }
}
