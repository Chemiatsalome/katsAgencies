<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\RentCreditAllocation;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier 2 (design approved 2026-10-25): the deployment-gate commands
 * themselves — rent-credit:reconcile (dry run / --apply / --rollback) and
 * liability:triage-report — exercised end to end via the console, not
 * just the service methods they wrap.
 */
class RentCreditReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord, float $monthlyRent = 20000): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $block = Block::create(['property_id' => $property->id, 'name' => 'Cmd Test Block '.str()->random(6)]);
        $house = House::create([
            'block_id' => $block->id, 'house_number' => 'CMD-'.str()->random(6),
            'monthly_rent' => $monthlyRent, 'deposit_amount' => $monthlyRent, 'status' => 'vacant',
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant(
            Tenant::create(['name' => 'Cmd Test Tenant '.str()->random(6), 'phone' => '07'.random_int(10000000, 99999999)]),
            [
                'house_id' => $house->id, 'move_in_date' => now()->subMonths(3)->format('Y-m-d'),
                'monthly_rent' => $monthlyRent, 'deposit_required' => $house->deposit_amount, 'rent_due_day' => 1,
            ]
        );
    }

    public function test_dry_run_is_clean_on_a_healthy_tenancy(): void
    {
        $this->seed();

        $this->artisan('rent-credit:reconcile')
            ->expectsOutputToContain('zero mismatches')
            ->assertExitCode(0);
    }

    public function test_apply_creates_a_missing_allocation_for_an_already_existing_eligible_charge(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $source = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->subMonth()->year, 'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $source->id,
            'amount' => 28000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $source->recalculateStatus();
        $this->assertEquals('advance', $source->status);

        $target = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $this->artisan('rent-credit:reconcile')
            ->expectsOutputToContain('Charges with eligible unapplied credit sitting unused: ')
            ->assertExitCode(0);

        $this->assertEquals(0, RentCreditAllocation::active()->count(), 'Dry run must never write anything.');

        $this->artisan('rent-credit:reconcile --apply')
            ->expectsOutputToContain('Created 1 allocation row(s).')
            ->assertExitCode(0);

        $this->assertEquals(8000.0, (float) RentCreditAllocation::where('target_rent_charge_id', $target->id)->active()->sum('amount'));

        // Idempotent — running --apply again finds nothing left to do.
        $this->artisan('rent-credit:reconcile --apply')
            ->expectsOutputToContain('Created 0 allocation row(s).')
            ->assertExitCode(0);
    }

    public function test_rollback_reverses_every_active_allocation(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $source = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->subMonth()->year, 'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $source->id,
            'amount' => 25000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $source->recalculateStatus();

        RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 5000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $this->artisan('rent-credit:reconcile --apply')->assertExitCode(0);
        $this->assertGreaterThan(0, RentCreditAllocation::active()->count());

        $this->artisan('rent-credit:reconcile --rollback')
            ->expectsOutputToContain('still active (expect 0)')
            ->assertExitCode(0);

        $this->assertEquals(0, RentCreditAllocation::active()->count());
        $this->assertGreaterThan(0, RentCreditAllocation::whereNotNull('reversed_at')->count(), 'Rows are reversed, never deleted.');
    }

    public function test_liability_triage_report_is_read_only_and_lists_undecided_items(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->first();

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = \App\Models\Expense::create([
            'property_id' => $property->id, 'house_id' => $house?->id,
            'expense_category_id' => $category->id, 'amount' => 1500,
            'expense_date' => now()->subMonths(4)->format('Y-m-d'), 'description' => 'Triage test expense',
        ]);

        $before = \App\Models\TenantLiabilityDecision::count();

        $this->artisan('liability:triage-report')
            ->expectsOutputToContain("Expense #{$expense->id}")
            ->assertExitCode(0);

        $this->assertEquals($before, \App\Models\TenantLiabilityDecision::count(), 'Read-only — must never create a decision itself.');
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'deposit_deduction_id' => null]);
    }
}
