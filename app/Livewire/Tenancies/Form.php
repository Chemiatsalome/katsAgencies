<?php

namespace App\Livewire\Tenancies;

use App\Models\Block;
use App\Models\House;
use App\Models\LandlordSetting;
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

    /** Drives notice_period_days from a landlord-facing month picker — '', '1'..'12' or 'custom' (exact days). */
    public string $notice_period_months = '';

    public string $monthly_rent = '';

    public string $deposit_required = '';

    public string $initial_deposit_paid = '';

    /** Drives initial_deposit_paid from a preset picker — 'none', 'one_month', 'two_months', 'full' or 'custom' (exact amount). */
    public string $deposit_option = 'none';

    public string $deposit_method = 'cash';

    public int $rent_due_day = 1;

    public bool $acknowledgeReturningTenant = false;

    public function mount(): void
    {
        $this->authorize('create', Tenancy::class);
        $this->move_in_date = now()->format('Y-m-d');

        $landlordId = Auth::user()->landlordId();
        $defaultDueDay = $landlordId ? (int) LandlordSetting::get($landlordId, 'default_rent_due_day', 5) : 5;
        $this->rent_due_day = $defaultDueDay;
        $this->new_tenant_payment_due_day = $defaultDueDay;

        if (request()->query('house_id')) {
            $house = House::with('block')->find((int) request()->query('house_id'));
            if ($house) {
                $this->block_id = $house->block_id;
                $this->property_id = $house->block->property_id;
                $this->house_id = $house->id;
                $this->syncRentFromHouse();
            }
        } elseif (request()->query('property_id')) {
            // Arriving from a property dashboard with more than one vacant
            // unit — there's no single right house to preselect, but
            // narrowing straight to this property (instead of a blank
            // "pick any property" dropdown) still saves a step, and the
            // unit dropdown itself is already filtered to vacant units.
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

    public function updatedMonthlyRent(): void
    {
        $this->recalculateDepositFromOption();
    }

    public function updatedDepositRequired(): void
    {
        $this->recalculateDepositFromOption();
    }

    public function updatedDepositOption(): void
    {
        $this->recalculateDepositFromOption();
    }

    public function updatedMoveInDate(): void
    {
        $this->recalculateNoticeDaysFromMonths();
    }

    public function updatedNoticePeriodMonths(): void
    {
        $this->recalculateNoticeDaysFromMonths();
    }

    public function updatedTenantId(): void
    {
        $tenant = Tenant::find($this->tenant_id);
        if ($tenant) {
            $this->rent_due_day = $tenant->payment_due_day;
        }

        // A different tenant than whatever history was being acknowledged
        // before — don't let a stale confirmation silently carry over.
        $this->acknowledgeReturningTenant = false;
    }

    /** Any tenancy this tenant has ever had, active or ended — flagged so the landlord gets a chance to confirm this is intentional (e.g. re-adding someone whose previous tenancy already ended) rather than a duplicate pick. */
    private function returningTenantHistory(): \Illuminate\Support\Collection
    {
        if ($this->newTenant || ! $this->tenant_id) {
            return collect();
        }

        return Tenant::find($this->tenant_id)?->tenancies()->with('house.block.property')->latest('move_in_date')->get() ?? collect();
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

    /**
     * Keeps initial_deposit_paid in sync with whichever preset is selected,
     * recomputed against the unit's actual monthly_rent/deposit_required —
     * never a hard-coded figure. 'custom' is left alone: that's the
     * landlord typing their own exact amount.
     */
    private function recalculateDepositFromOption(): void
    {
        $rent = (float) ($this->monthly_rent ?: 0);
        $required = (float) ($this->deposit_required ?: 0);

        $this->initial_deposit_paid = match ($this->deposit_option) {
            'none' => '0',
            'one_month' => (string) min($rent, $required),
            'two_months' => (string) min($rent * 2, $required),
            'full' => (string) $required,
            default => $this->initial_deposit_paid,
        };
    }

    /**
     * Presets offered for "deposit paid" depend on the unit's actual
     * configuration — not every property requires two months' deposit, so
     * the one/two-month presets only appear when they're genuinely a
     * distinct, smaller amount than the full required deposit.
     *
     * @return array<int, array{key: string, label: string, amount: float|null}>
     */
    public function depositPresetOptions(): array
    {
        $rent = (float) ($this->monthly_rent ?: 0);
        $required = (float) ($this->deposit_required ?: 0);

        $options = [
            ['key' => 'none', 'label' => 'No deposit paid', 'amount' => 0.0],
        ];

        if ($rent > 0 && $rent < $required) {
            $options[] = ['key' => 'one_month', 'label' => "One month's rent", 'amount' => $rent];
        }

        if ($rent > 0 && $rent * 2 < $required) {
            $options[] = ['key' => 'two_months', 'label' => "Two months' rent", 'amount' => $rent * 2];
        }

        $options[] = ['key' => 'full', 'label' => 'Deposit paid in full', 'amount' => $required];
        $options[] = ['key' => 'custom', 'label' => 'Partial / custom amount', 'amount' => null];

        return $options;
    }

    /**
     * Converts the landlord's month-based notice period into the stored
     * day count using real calendar arithmetic anchored on the actual
     * move-in date (addMonthsNoOverflow) — never a flat "1 month = 30
     * days" guess, which under- or over-counts depending which months the
     * notice period spans. Left untouched for '' (not specified) and
     * 'custom' (the landlord typing an exact day count themselves).
     */
    private function recalculateNoticeDaysFromMonths(): void
    {
        if ($this->notice_period_months === '' || $this->notice_period_months === 'custom') {
            return;
        }

        $months = (int) $this->notice_period_months;
        $anchor = \Carbon\Carbon::parse($this->move_in_date ?: now());

        $this->notice_period_days = (string) $anchor->copy()->addMonthsNoOverflow($months)->diffInDays($anchor);
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

        // Re-checked server-side, same reasoning as the unpaid-balance
        // checkbox elsewhere — a landlord picking a tenant who already has
        // tenancy history (e.g. one that was ended and is now being added
        // again) should see that before it's too late to reconsider.
        if ($this->returningTenantHistory()->isNotEmpty() && ! $this->acknowledgeReturningTenant) {
            $this->addError('acknowledgeReturningTenant', 'This tenant has prior tenancy history — check the box below to confirm you want to add them again.');

            return;
        }

        $house = House::findOrFail($this->house_id);
        $this->authorize('update', $house);

        if ($house->status !== 'vacant') {
            $this->addError('house_id', 'This unit is no longer vacant — pick another one.');

            return;
        }

        // Checked against the actual Tenancy rows, not just the house's own
        // status flag — that flag is supposed to track this already, but
        // trusting it alone is exactly how two tenancies ended up active on
        // the same unit at once in the first place. A unit genuinely can't
        // have two occupants at the same time, so this is a hard stop, not
        // another confirmation checkbox.
        if (Tenancy::where('house_id', $house->id)->whereIn('status', Tenancy::OCCUPYING_STATUSES)->exists()) {
            $this->addError('house_id', 'This unit already has an active tenancy on record — end that one first before adding a new tenant here.');

            return;
        }

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

        // The "just created" signal travels as a query param on the
        // destination URL, not a session flash — Livewire's component
        // update requests run with middleware (including session
        // persistence) disabled, so a flash set here is not reliably
        // readable even on the very next real page load. A query param has
        // no such dependency.
        //
        // Not a Livewire navigate:true redirect either — that always
        // *pushes* a new history entry, leaving this now-completed form as
        // the entry right before the tenancy page. The browser's own Back
        // button (not just an in-page link) would then reopen a stale,
        // already-submitted form. Dispatching this and replacing the URL
        // client-side (see form.blade.php) instead collapses this form's
        // history entry into the tenancy page, so Back skips straight past
        // it.
        $this->dispatch('tenancy-created', url: route('manage.tenancies.show', ['tenancy' => $tenancy, 'created' => 1]));
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

        $tenantsQuery = Tenant::orderBy('name');

        return view('livewire.tenancies.form', [
            'properties' => $properties,
            'blocks' => $blocks,
            'availableHouses' => $housesQuery->get(),
            'availableTenants' => $tenantsQuery->get(),
            'returningTenantHistory' => $this->returningTenantHistory(),
            'depositPresetOptions' => $this->depositPresetOptions(),
        ]);
    }
}
