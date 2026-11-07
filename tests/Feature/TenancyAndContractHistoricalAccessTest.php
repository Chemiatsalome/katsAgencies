<?php

namespace Tests\Feature;

use App\Livewire\Tenant\Contract;
use App\Livewire\Tenant\MyTenancy;
use App\Models\ContractTemplate;
use App\Models\House;
use App\Models\Tenancy;
use App\Models\TenancyChangeRequest;
use App\Models\User;
use App\Services\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 6 audit, P6-H-01/P6-M-01/P6-M-02: My Tenancy and My Contract both
 * used to resolve only the active tenancy — a tenant with no occupying
 * tenancy at all (every one they've had has ended) lost both pages
 * entirely, even though the underlying Tenancy/Contract/Document rows were
 * still fully intact and authorized. Both pages now fall back to the most
 * recent tenancy for DISPLAY only; every write action keeps resolving the
 * active tenancy independently and unchanged, so none of them gained the
 * ability to act against a historical one.
 */
class TenancyAndContractHistoricalAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUser(): User
    {
        $tenancy = Tenancy::active()->firstOrFail();
        $tenant = $tenancy->tenant;

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenant->update(['user_id' => $user->id]);

        return $user;
    }

    private function endOnlyTenancy(User $user): Tenancy
    {
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();
        $tenancy->update(['status' => 'completed', 'move_out_date' => now()->subMonth()]);

        return $tenancy->fresh();
    }

    public function test_my_tenancy_shows_the_most_recent_tenancy_when_none_is_active(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $this->endOnlyTenancy($user);

        Livewire::actingAs($user)
            ->test(MyTenancy::class)
            ->assertSee('Most Recent Tenancy')
            ->assertSee($tenancy->house->house_number)
            ->assertDontSee('No tenancy is linked to your account yet.');
    }

    public function test_contract_page_shows_the_most_recent_tenancys_contract_when_none_is_active(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $this->endOnlyTenancy($user);
        $landlord = $tenancy->house->block->property->landlord;

        $contract = app(ContractService::class)->generateForTenancy($tenancy, ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Historical Template',
            'body' => 'Tenant: {{tenant_name}}',
            'is_default' => true,
            'is_active' => true,
        ]));
        app(ContractService::class)->sign($contract, $tenancy->tenant->name, '127.0.0.1');

        Livewire::actingAs($user)
            ->test(Contract::class)
            ->assertSee('This tenancy has ended')
            ->assertSee('Signed By')
            ->assertDontSee('No tenancy is linked to your account yet.');
    }

    /** Historical contract access means the signed document itself must still be downloadable, not just the page rendering. */
    public function test_a_tenant_can_still_download_their_signed_contract_after_their_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $this->endOnlyTenancy($user);
        $landlord = $tenancy->house->block->property->landlord;

        $contract = app(ContractService::class)->generateForTenancy($tenancy, ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Historical Download Template',
            'body' => 'Tenant: {{tenant_name}}',
            'is_default' => true,
            'is_active' => true,
        ]));
        app(ContractService::class)->sign($contract, $tenancy->tenant->name, '127.0.0.1');

        $this->actingAs($user)
            ->get(route('documents.show', $contract->document))
            ->assertOk();
    }

    public function test_my_tenancy_prefers_the_active_tenancy_over_a_historical_one_when_both_exist(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $activeTenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $vacantHouse = House::vacant()
            ->whereHas('block.property', fn ($q) => $q->where('landlord_id', $activeTenancy->house->block->property->landlord_id))
            ->first();
        $this->assertNotNull($vacantHouse, 'Seeded data must include a vacant house on the same landlord to run this scenario.');

        Tenancy::create([
            'tenant_id' => $user->tenant->id,
            'house_id' => $vacantHouse->id,
            'move_in_date' => now()->subYears(2),
            'move_out_date' => now()->subYear(),
            'monthly_rent' => 5000,
            'rent_due_day' => 1,
            'status' => 'completed',
        ]);

        Livewire::actingAs($user)
            ->test(MyTenancy::class)
            ->assertSee('Current Tenancy')
            ->assertDontSee('Most Recent Tenancy')
            ->assertSee($activeTenancy->house->house_number);
    }

    public function test_submitting_a_change_request_is_still_blocked_once_the_only_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $this->endOnlyTenancy($user);

        // submitChangeRequest()'s own private currentTenancy() helper
        // (unchanged by this fix) aborts 404 when no active tenancy exists
        // — matches the same abort_if() pattern already established for
        // Statements::downloadReceipt() in earlier phases of this audit.
        Livewire::actingAs($user)->test(MyTenancy::class)
            ->set('change_requested_change', 'Please lower my rent')
            ->call('submitChangeRequest')
            ->assertStatus(404);
    }

    public function test_submitting_a_renewal_request_is_still_blocked_once_the_only_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $this->endOnlyTenancy($user);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->call('submitRenewalRequest')
            ->assertStatus(404);
    }

    public function test_signing_is_still_blocked_once_the_only_tenancy_has_ended_even_with_an_awaiting_contract(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        $contract = app(ContractService::class)->generateForTenancy($tenancy, ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Ends Before Signing Template',
            'body' => 'Tenant: {{tenant_name}}',
            'is_default' => true,
            'is_active' => true,
        ]));
        app(ContractService::class)->markSent($contract);

        // The tenancy ends before the tenant ever gets to sign.
        $tenancy->update(['status' => 'completed', 'move_out_date' => now()]);

        Livewire::actingAs($user)->test(Contract::class)
            ->set('agreedToTerms', true)
            ->set('typedFullName', $tenancy->tenant->name)
            ->call('sign')
            ->assertStatus(403);

        $this->assertEquals('awaiting_signature', $contract->fresh()->status, 'Must not have been signed.');
    }

    public function test_requesting_a_due_day_change_is_still_blocked_once_the_only_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $this->endOnlyTenancy($user);

        Livewire::actingAs($user)->test(Contract::class)
            ->set('requestedDueDay', '5')
            ->call('requestDueDayChange')
            ->assertStatus(404);
    }

    /**
     * Phase 6 audit, P6-M-01: water/electricity arrangement was never shown
     * to the tenant anywhere despite being configured per unit.
     */
    public function test_my_tenancy_shows_all_three_utility_arrangement_labels(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $tenancy->house->update(['water_arrangement' => 'shared', 'electricity_arrangement' => 'separate_meter']);
        Livewire::actingAs($user)->test(MyTenancy::class)
            ->assertSee('Shared')
            ->assertSee('Separately metered');

        $tenancy->house->update(['water_arrangement' => 'included', 'electricity_arrangement' => 'included']);
        Livewire::actingAs($user)->test(MyTenancy::class)
            ->assertSeeInOrder(['Included in rent', 'Included in rent']);
    }

    public function test_my_tenancy_degrades_gracefully_for_a_missing_utility_arrangement_value(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        // forceFill + a raw update bypasses the enum cast's own validation,
        // simulating a legacy/unexpected value already sitting in the
        // column rather than one entered through the current form.
        \Illuminate\Support\Facades\DB::table('houses')->where('id', $tenancy->house_id)->update(['water_arrangement' => '']);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->assertSee('Not specified');
    }

    /**
     * Phase 6 audit, P6-M-02 — corrected during implementation: notice
     * evidence turned out to already be attached to the TenancyNotice
     * itself (documentable_type = TenancyNotice, confirmed in
     * TenancyNoticeService), not the Tenancy as the original audit assumed,
     * so it was never actually selected by the generic Documents query
     * (documentable_type = Tenancy) to begin with — there was no real
     * duplicate via that path. This locks in the correct, already-working
     * behaviour: the evidence shows exactly once, via the notice's own
     * section, never a second time in the generic list below it.
     */
    public function test_tenancy_notice_evidence_appears_only_once_via_the_notice_section_not_the_generic_documents_list(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $notice = \App\Models\TenancyNotice::create([
            'tenancy_id' => $tenancy->id,
            'sequence_number' => 1,
            'notice_date' => now(),
            'reason_category' => 'other',
            'status' => 'issued',
        ]);

        \App\Models\Document::create([
            'documentable_type' => $notice->getMorphClass(),
            'documentable_id' => $notice->id,
            'category' => 'tenancy_notice_evidence',
            'original_filename' => 'notice-evidence-unique-name.pdf',
            'path' => 'documents/notice-evidence-unique-name.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'uploaded_by' => $tenancy->house->block->property->landlord_id,
        ]);

        $html = Livewire::actingAs($user)->test(MyTenancy::class)->html();

        $this->assertEquals(1, substr_count($html, 'notice-evidence-unique-name.pdf'), 'The evidence document must appear exactly once (via the notice section), never duplicated in the generic Documents list.');
    }

    /** Same correction as above, for change-request evidence (documentable_type = TenancyChangeRequest, confirmed in TenancyChangeRequestService). */
    public function test_change_request_evidence_is_not_duplicated_in_the_generic_documents_list(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $request = TenancyChangeRequest::create([
            'tenancy_id' => $tenancy->id,
            'category' => 'other',
            'requested_change' => 'Something',
            'status' => 'pending',
        ]);

        \App\Models\Document::create([
            'documentable_type' => $request->getMorphClass(),
            'documentable_id' => $request->id,
            'category' => 'change_request_evidence',
            'original_filename' => 'change-request-evidence-unique-name.pdf',
            'path' => 'documents/change-request-evidence-unique-name.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'uploaded_by' => $tenancy->house->block->property->landlord_id,
        ]);

        $html = Livewire::actingAs($user)->test(MyTenancy::class)->html();

        $this->assertEquals(0, substr_count($html, 'change-request-evidence-unique-name.pdf'), 'Change-request evidence is not attached to the Tenancy, so it must never appear in the generic Documents list, which only ever queries Tenancy-attached documents.');
    }

    /**
     * Phase 6 audit, verification round 2: a TenancyNotice's own open/
     * acknowledged status is entirely independent of its tenancy's status
     * (confirmed: TenancyNotice::OPEN_STATUSES has no tie to Tenancy at
     * all), so a notice left open when a tenancy ends stays "open" forever
     * — isOpen() alone was never enough to block a response once the
     * tenancy itself has ended. ownNotice() deliberately still scopes by
     * tenant_id across every tenancy (read access to history is correct),
     * so the new guard is specifically "does this notice belong to the
     * tenant's *currently active* tenancy", checked independently of it.
     */
    private function endedTenancyWithOpenNotice(User $user): array
    {
        $tenancy = $this->endOnlyTenancy($user);

        $notice = \App\Models\TenancyNotice::create([
            'tenancy_id' => $tenancy->id,
            'sequence_number' => 1,
            'notice_date' => now()->subMonths(2),
            'reason_category' => 'other',
            'status' => 'issued',
        ]);

        return [$tenancy, $notice];
    }

    public function test_acknowledging_a_notice_is_blocked_once_its_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        [, $notice] = $this->endedTenancyWithOpenNotice($user);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->call('acknowledgeNotice', $notice->id)
            ->assertStatus(404);

        $this->assertNull($notice->fresh()->acknowledged_at);
    }

    public function test_disputing_a_notice_is_blocked_once_its_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        [, $notice] = $this->endedTenancyWithOpenNotice($user);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->set('dispute_note', 'I disagree with this notice')
            ->call('submitDispute', $notice->id)
            ->assertStatus(404);

        $this->assertNull($notice->fresh()->tenant_response_type);
    }

    public function test_requesting_additional_time_on_a_notice_is_blocked_once_its_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        [, $notice] = $this->endedTenancyWithOpenNotice($user);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->set('requested_new_vacate_date', now()->addMonth()->toDateString())
            ->call('submitRequestTime', $notice->id)
            ->assertStatus(404);

        $this->assertNull($notice->fresh()->tenant_response_type);
    }

    public function test_requesting_an_agreement_extension_on_a_notice_is_blocked_once_its_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        [, $notice] = $this->endedTenancyWithOpenNotice($user);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->call('submitRequestExtension', $notice->id)
            ->assertStatus(404);

        $this->assertNull($notice->fresh()->tenant_response_type);
    }

    /**
     * Same gap, found while verifying the renewal-request action:
     * TenancyRenewalRequest's 'alternative_proposed' status is likewise
     * independent of its tenancy's own status.
     */
    public function test_responding_to_a_proposed_renewal_alternative_is_blocked_once_its_tenancy_has_ended(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $this->endOnlyTenancy($user);

        $request = \App\Models\TenancyRenewalRequest::create([
            'tenancy_id' => $tenancy->id,
            'requested_duration_months' => 12,
            'status' => 'alternative_proposed',
            'alternative_duration_months' => 6,
        ]);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->call('respondToRenewalAlternative', $request->id, true)
            ->assertStatus(404);

        $this->assertEquals('alternative_proposed', $request->fresh()->status, 'Must not have been accepted or declined.');
    }

    /**
     * Final verification requested before committing: confirms
     * assertBelongsToActiveTenancy() uses exactly the same definition of
     * "occupying" as Tenancy::OCCUPYING_STATUSES, not a separate one —
     * traced directly: assertBelongsToActiveTenancy() calls this
     * component's own currentTenancy(), which calls
     * Tenant::currentTenancy(), which calls Tenancy::scopeActive(), which
     * is defined as whereIn('status', self::OCCUPYING_STATUSES) — one
     * single source of truth, confirmed here for all three statuses it
     * actually lists ('active', 'expiring_soon', 'notice_given'), not just
     * the plain 'active' case every other test in this file already covers
     * implicitly via the seeded data.
     */
    public function test_a_guarded_action_succeeds_for_every_status_in_tenancy_occupying_statuses(): void
    {
        $this->assertEquals(['active', 'expiring_soon', 'notice_given'], \App\Models\Tenancy::OCCUPYING_STATUSES);

        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        foreach (\App\Models\Tenancy::OCCUPYING_STATUSES as $i => $status) {
            $tenancy->update(['status' => $status]);

            $notice = \App\Models\TenancyNotice::create([
                'tenancy_id' => $tenancy->id,
                'sequence_number' => $i + 1,
                'notice_date' => now(),
                'reason_category' => 'other',
                'status' => 'issued',
            ]);

            Livewire::actingAs($user)->test(MyTenancy::class)
                ->call('acknowledgeNotice', $notice->id)
                ->assertStatus(200);

            $this->assertNotNull($notice->fresh()->acknowledged_at, "acknowledgeNotice() must succeed while the tenancy's status is '{$status}'.");
        }
    }

    /**
     * Final verification requested before committing: all five newly
     * guarded methods, specifically against a 'terminated' tenancy (the
     * prior round's tests used 'completed' — both are non-occupying, and
     * both must behave identically since the guard has no per-status
     * special-casing).
     */
    public function test_all_five_newly_guarded_actions_remain_blocked_for_a_terminated_tenancy(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();
        $tenancy->update(['status' => 'terminated', 'move_out_date' => now()]);

        $notice = \App\Models\TenancyNotice::create([
            'tenancy_id' => $tenancy->id,
            'sequence_number' => 1,
            'notice_date' => now()->subMonths(2),
            'reason_category' => 'other',
            'status' => 'issued',
        ]);
        $renewalRequest = \App\Models\TenancyRenewalRequest::create([
            'tenancy_id' => $tenancy->id,
            'requested_duration_months' => 12,
            'status' => 'alternative_proposed',
            'alternative_duration_months' => 6,
        ]);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->call('acknowledgeNotice', $notice->id)
            ->assertStatus(404);
        $this->assertNull($notice->fresh()->acknowledged_at);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->set('dispute_note', 'Disagree')
            ->call('submitDispute', $notice->id)
            ->assertStatus(404);
        $this->assertNull($notice->fresh()->tenant_response_type);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->set('requested_new_vacate_date', now()->addMonth()->toDateString())
            ->call('submitRequestTime', $notice->id)
            ->assertStatus(404);
        $this->assertNull($notice->fresh()->tenant_response_type);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->call('submitRequestExtension', $notice->id)
            ->assertStatus(404);
        $this->assertNull($notice->fresh()->tenant_response_type);

        Livewire::actingAs($user)->test(MyTenancy::class)
            ->call('respondToRenewalAlternative', $renewalRequest->id, true)
            ->assertStatus(404);
        $this->assertEquals('alternative_proposed', $renewalRequest->fresh()->status);
    }
}
