<?php

namespace App\Livewire\TenantInvitations;

use App\Livewire\Concerns\ManagesTenancyTermFields;
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
    use ManagesTenancyTermFields;

    public ?int $property_id = null;

    public ?int $block_id = null;

    public ?int $house_id = null;

    public bool $forExistingTenant = false;

    public ?int $existing_tenant_id = null;

    public bool $acknowledgeReturningTenant = false;

    public string $monthly_rent = '';

    public string $deposit_required = '';

    public string $initial_deposit_paid = '';

    public string $deposit_method = 'cash';

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

        // Mirrors Add Tenant's own mount() — arriving from a property's
        // "Invite a tenant" link for its one vacant unit (or for the
        // property in general) should preselect it here too, instead of
        // only Add Tenant honouring that link's query params.
        if (request()->query('house_id')) {
            $house = House::with('block')->find((int) request()->query('house_id'));
            if ($house) {
                $this->block_id = $house->block_id;
                $this->property_id = $house->block->property_id;
                $this->house_id = $house->id;
                $this->syncRentFromHouse();
            }
        } elseif (request()->query('property_id')) {
            $property = Property::find((int) request()->query('property_id'));
            if ($property) {
                $this->property_id = $property->id;
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

    public function updatedExistingTenantId(): void
    {
        // A different tenant than whatever history was being acknowledged
        // before — don't let a stale confirmation silently carry over.
        $this->acknowledgeReturningTenant = false;
    }

    /** Any tenancy this tenant has ever had, active or ended — flagged so the landlord gets a chance to confirm this is intentional (e.g. re-inviting someone whose previous tenancy already ended) rather than a duplicate pick. */
    private function returningTenantHistory(): \Illuminate\Support\Collection
    {
        if (! $this->forExistingTenant || ! $this->existing_tenant_id) {
            return collect();
        }

        return Tenant::find($this->existing_tenant_id)?->tenancies()->with('house.block.property')->latest('move_in_date')->get() ?? collect();
    }

    private function syncRentFromHouse(): void
    {
        $house = House::find($this->house_id);
        if ($house) {
            $this->monthly_rent = (string) $house->monthly_rent;
            $this->deposit_required = (string) $house->deposit_amount;
            $this->recalculateDepositFromOption();
        }
    }

    public function generate(): void
    {
        $rules = [
            'property_id' => ['required', 'exists:properties,id'],
            'block_id' => ['required', 'exists:blocks,id'],
            'house_id' => ['required', 'exists:houses,id'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'deposit_required' => ['required', 'numeric', 'min:0'],
            'initial_deposit_paid' => ['nullable', 'numeric', 'min:0'],
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

        // Re-checked server-side, same reasoning as the identical guard on
        // Add Tenant — a landlord picking a tenant who already has tenancy
        // history (e.g. one that was ended and is now being invited again)
        // should see that before it's too late to reconsider.
        if ($this->returningTenantHistory()->isNotEmpty() && ! $this->acknowledgeReturningTenant) {
            $this->addError('acknowledgeReturningTenant', 'This tenant has prior tenancy history — check the box below to confirm you want to invite them again.');

            return;
        }

        $house = House::findOrFail($this->house_id);
        $this->authorize('update', $house);

        if ($house->status !== 'vacant') {
            $this->addError('house_id', 'This unit is no longer available.');

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
            'initial_deposit_paid' => $this->initial_deposit_paid ?: 0,
            'deposit_method' => $this->deposit_method,
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
            'returningTenantHistory' => $this->returningTenantHistory(),
            'depositPresetOptions' => $this->depositPresetOptions(),
        ]);
    }
}
