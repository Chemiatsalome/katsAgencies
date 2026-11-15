<?php

namespace Tests\Feature;

use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F8 (account lifecycle audit): disbursements.landlord_id had no explicit
 * onDelete() clause — the database already blocked a landlord delete
 * through its own implicit default, but that was never a stated,
 * intentional rule. See
 * 2026_11_15_120000_make_disbursements_landlord_id_restrict_on_delete.php
 * for the migration that makes it an explicit restrictOnDelete(). No
 * landlord-deletion feature exists anywhere in the application (F1 removed
 * the one generic path that could have reached it for any role), so there
 * is no application-level deletion attempt left to wrap in graceful
 * exception handling — this covers the database constraint itself.
 */
class DisbursementDeletionProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeDisbursementFor(User $landlord): Disbursement
    {
        return Disbursement::create([
            'landlord_id' => $landlord->id,
            'amount' => 15000,
            'disbursed_at' => now()->format('Y-m-d'),
            'recorded_by' => $landlord->id,
        ]);
    }

    public function test_the_landlord_foreign_key_on_disbursements_is_explicitly_restrictive(): void
    {
        $constraint = \Illuminate\Support\Facades\DB::selectOne(
            "select * from pragma_foreign_key_list('disbursements') where \"table\" = 'users' and \"from\" = 'landlord_id'"
        );

        $this->assertNotNull($constraint, 'Expected an explicit foreign key from disbursements.landlord_id to users.');
        $this->assertSame('RESTRICT', strtoupper($constraint->on_delete));
    }

    public function test_a_landlord_with_an_existing_disbursement_cannot_be_deleted(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $disbursement = $this->makeDisbursementFor($landlord);

        $this->expectException(QueryException::class);

        $landlord->delete();

        // Never reached if the expected exception fired, but guards the
        // test's own intent if the database constraint is ever weakened.
        $this->assertDatabaseHas('disbursements', ['id' => $disbursement->id]);
    }

    public function test_the_disbursement_record_is_unchanged_after_a_blocked_deletion_attempt(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $disbursement = $this->makeDisbursementFor($landlord);

        try {
            $landlord->delete();
        } catch (QueryException $e) {
            // Expected — the constraint did its job.
        }

        $this->assertDatabaseHas('disbursements', [
            'id' => $disbursement->id,
            'landlord_id' => $landlord->id,
            'amount' => 15000,
        ]);
        $this->assertDatabaseHas('users', ['id' => $landlord->id]);
    }

    /**
     * The migration only changes landlord_id's onDelete() clause — it
     * doesn't touch validation or business logic, so this confirms normal
     * recording, status transitions and querying still work exactly as
     * before. The full admin recording workflow (owed-amount validation,
     * duplicate-reference checks, confirmation/dispute/reversal) already
     * has its own dedicated coverage in LandlordPayoutWorkflowTest, run as
     * regression for this task rather than duplicated here.
     */
    public function test_normal_disbursement_creation_and_status_updates_are_unaffected(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $disbursement = $this->makeDisbursementFor($landlord);
        $this->assertEquals('awaiting_landlord_confirmation', $disbursement->status);

        $disbursement->update(['status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $landlord->id]);

        $this->assertDatabaseHas('disbursements', [
            'id' => $disbursement->id,
            'landlord_id' => $landlord->id,
            'status' => 'confirmed',
        ]);
    }
}
