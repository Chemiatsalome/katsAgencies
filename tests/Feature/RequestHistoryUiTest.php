<?php

namespace Tests\Feature;

use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenant\Contract as TenantContract;
use App\Livewire\Tenant\Rent as TenantRent;
use App\Models\ContractTemplate;
use App\Models\DueDayChangeRequest;
use App\Models\House;
use App\Models\LateFeeWaiverRequest;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit finding C7: LateFeeWaiverRequest and DueDayChangeRequest never got
 * the "always show full history" treatment the three newer Tenancy-family
 * models (Notice/ChangeRequest/RenewalRequest) got this session — only the
 * single latest request/decision was ever surfaced, on either side; older
 * decided requests existed in the DB but were invisible in any UI.
 */
class RequestHistoryUiTest extends TestCase
{
    use RefreshDatabase;

    private function makeDefaultTemplate(User $landlord): ContractTemplate
    {
        return ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Standard Agreement',
            'body' => "Tenant: {{tenant_name}}\nProperty: {{property_name}}\nUnit: {{unit_number}}\nRent: {{monthly_rent}}",
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function createTenancyFor(User $landlord): \App\Models\Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'History UI Test Tenant',
            'phone' => '0700111000',
            'payment_due_day' => 1,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonths(2)->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    public function test_tenant_contract_page_shows_every_past_due_day_change_decision_not_just_the_latest(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenancy->tenant, $tenancy->tenant->name, 'history-contract-tenant@example.test', 'password');

        DueDayChangeRequest::create([
            'tenancy_id' => $tenancy->id, 'requested_due_day' => 10, 'status' => 'rejected',
            'decided_at' => now()->subDays(20), 'decision_note' => 'Too close to rent cycle start.',
        ]);
        DueDayChangeRequest::create([
            'tenancy_id' => $tenancy->id, 'requested_due_day' => 15, 'status' => 'accepted',
            'decided_at' => now()->subDays(5),
        ]);

        $component = Livewire::actingAs($tenantUser)->test(TenantContract::class);
        $history = $component->viewData('dueDayHistory');

        $this->assertCount(2, $history);
        $component->call('toggleDueDayHistory')->assertSee('Too close to rent cycle start');
    }

    public function test_landlord_tenancy_page_shows_every_past_due_day_and_waiver_decision(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        DueDayChangeRequest::create([
            'tenancy_id' => $tenancy->id, 'requested_due_day' => 10, 'status' => 'rejected',
            'decided_at' => now()->subDays(20), 'decision_note' => 'Not this time.',
        ]);
        DueDayChangeRequest::create([
            'tenancy_id' => $tenancy->id, 'requested_due_day' => 15, 'status' => 'accepted',
            'decided_at' => now()->subDays(5),
        ]);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20500, 'amount_paid' => 0, 'status' => 'overdue',
            'late_fee_amount' => 500,
        ]);
        LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'rejected', 'decided_at' => now()->subDays(10), 'decision_note' => 'Already late once this year.',
        ]);
        LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'accepted', 'decided_at' => now()->subDay(),
        ]);

        $component = Livewire::actingAs($landlord)->test(TenancyShow::class, ['tenancy' => $tenancy]);

        $this->assertCount(2, $component->viewData('dueDayChangeHistory'));
        $this->assertCount(2, $component->viewData('waiverRequestHistory'));

        $component->call('toggleDueDayHistory')->assertSee('Not this time');
        $component->call('toggleWaiverHistory')->assertSee('Already late once this year');
    }

    public function test_tenant_rent_page_exposes_every_past_waiver_request_for_a_charge_not_just_the_latest(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenancy->tenant, $tenancy->tenant->name, 'history-rent-tenant@example.test', 'password');

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20500, 'amount_paid' => 0, 'status' => 'overdue',
            'late_fee_amount' => 500,
        ]);
        LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'rejected', 'decided_at' => now()->subDays(10), 'decision_note' => 'First decline.',
        ]);
        LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'pending',
        ]);

        $component = Livewire::actingAs($tenantUser)->test(TenantRent::class)->set('year', (int) now()->year);
        $rentCharges = $component->viewData('rentCharges');
        $renderedCharge = $rentCharges->firstWhere('id', $charge->id);

        $this->assertCount(2, $renderedCharge->waiverRequests);
        $component->assertSee('Show past requests');
    }

    /**
     * My Rent's "Payments this period" line used to join every payment for
     * a charge into one comma-separated run-on sentence — fine for one or
     * two payments, unreadable once several accumulate against a single
     * period (e.g. a handful of small test STK pushes). Past 3, it now
     * collapses behind a toggle instead of growing indefinitely; at or
     * under 3 it stays a plain always-visible list, just one line per
     * payment instead of a comma-joined paragraph.
     */
    public function test_tenant_rent_page_collapses_many_payments_for_one_period_behind_a_toggle(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenancy->tenant, $tenancy->tenant->name, 'history-payments-tenant@example.test', 'password');

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);

        foreach (['DIRECT22', 'DIRECT16', 'DIRECT50', 'DIRECT10'] as $reference) {
            Payment::create([
                'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
                'amount' => 10, 'payment_date' => now()->format('Y-m-d'), 'method' => 'mpesa',
                'reference_number' => $reference, 'status' => 'confirmed', 'recorded_by' => $landlord->id,
            ]);
        }

        $component = Livewire::actingAs($tenantUser)->test(TenantRent::class)->set('year', (int) now()->year);

        // Collapsed behind a toggle, not dumped as one run-on line. Asserts
        // the raw, unescaped x-data attribute specifically — a regression
        // where it was built via {{ }} (which HTML-escapes its quotes into
        // &quot;, silently breaking Alpine's "open" state and leaving the
        // button's @click doing nothing) still passed every other assertion
        // here, since x-show/x-text text content was unaffected.
        $component->assertSee('4 · KSh 40.00 total')
            ->assertSeeHtml('x-data="{ open: false }"')
            ->assertSeeHtml('x-show="open"')
            ->assertDontSeeHtml('x-data=&quot;')
            ->assertDontSee('DIRECT22 · DIRECT16');

        $secondCharge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month === 1 ? 2 : now()->month - 1,
            'due_date' => now()->subMonth(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $secondCharge->id,
            'amount' => 20000, 'payment_date' => now()->subMonth()->format('Y-m-d'), 'method' => 'bank',
            'status' => 'confirmed', 'recorded_by' => $landlord->id,
        ]);

        // A single payment still renders as a plain list, no toggle needed.
        $rendered = Livewire::actingAs($tenantUser)->test(TenantRent::class)->set('year', (int) now()->year)->html();
        $this->assertSame(1, substr_count($rendered, 'x-show="open"'), 'Only the >3-payment period should get the collapse toggle.');
    }
}
