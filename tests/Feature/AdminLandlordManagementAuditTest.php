<?php

namespace Tests\Feature;

use App\Livewire\Admin\Landlords\Applications;
use App\Livewire\Admin\Landlords\Index as LandlordsIndex;
use App\Mail\LandlordApplicationApprovedPendingVerificationMail;
use App\Mail\LandlordApplicationRejectedMail;
use App\Models\RejectedLandlordApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase One admin-to-landlord communication audit: the application
 * approve/reject/reactivate flows send mail directly (not through
 * NotificationService), and previously had no failure handling at all — a
 * transport error would crash the Livewire action after the DB write
 * already happened (approve), or leave the admin with no idea the
 * applicant was never told why they were rejected (reject). These tests
 * cover both the happy path and the failure path for each.
 */
class AdminLandlordManagementAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejecting_an_application_emails_the_reason_records_a_snapshot_and_deletes_the_account(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $applicant = User::factory()->create(['status' => 'inactive', 'phone' => '0712345678', 'national_id' => '99988877']);
        $applicant->assignRole('landlord');

        Mail::fake();

        Livewire::actingAs($admin)
            ->test(Applications::class)
            ->call('openRejectForm', $applicant->id)
            ->set('rejectionReason', 'Could not verify the ID provided.')
            ->call('reject');

        Mail::assertSent(LandlordApplicationRejectedMail::class);

        $this->assertDatabaseMissing('users', ['id' => $applicant->id]);
        $this->assertDatabaseHas('rejected_landlord_applications', [
            'email' => $applicant->email,
            'phone' => '0712345678',
            'national_id' => '99988877',
            'reason' => 'Could not verify the ID provided.',
        ]);
    }

    public function test_rejecting_an_application_requires_a_reason(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $applicant = User::factory()->create(['status' => 'inactive']);
        $applicant->assignRole('landlord');

        Livewire::actingAs($admin)
            ->test(Applications::class)
            ->call('openRejectForm', $applicant->id)
            ->set('rejectionReason', '')
            ->call('reject')
            ->assertHasErrors(['rejectionReason' => 'required']);

        $this->assertDatabaseHas('users', ['id' => $applicant->id]);
    }

    /**
     * The rejection email is sent before any destructive write — if it
     * can't be delivered, the account must survive untouched (still
     * pending, no snapshot row) rather than vanish with no explanation
     * ever reaching the applicant.
     */
    public function test_rejecting_an_application_leaves_it_pending_if_the_email_cannot_be_sent(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $applicant = User::factory()->create(['status' => 'inactive']);
        $applicant->assignRole('landlord');

        Mail::shouldReceive('to->send')->once()->andThrow(new \RuntimeException('SMTP connection refused'));

        Livewire::actingAs($admin)
            ->test(Applications::class)
            ->call('openRejectForm', $applicant->id)
            ->set('rejectionReason', 'Could not verify the ID provided.')
            ->call('reject');

        $this->assertDatabaseHas('users', ['id' => $applicant->id, 'status' => 'inactive']);
        $this->assertDatabaseMissing('rejected_landlord_applications', ['email' => $applicant->email]);
        $this->assertDatabaseCount('rejected_landlord_applications', 0);
    }

    /**
     * Approval grants access by itself (the status/approved_at write) — a
     * failed confirmation email must not be mistaken for a failed
     * approval, and must not crash the action either.
     */
    public function test_approving_an_application_still_activates_the_account_if_the_email_cannot_be_sent(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $applicant = User::factory()->create(['status' => 'inactive']); // verified by factory default
        $applicant->assignRole('landlord');

        Mail::shouldReceive('to->send')->once()->andThrow(new \RuntimeException('SMTP connection refused'));

        Livewire::actingAs($admin)
            ->test(Applications::class)
            ->call('approve', $applicant->id);

        $applicant->refresh();
        $this->assertSame('active', $applicant->status);
        $this->assertNotNull($applicant->approved_at);
    }

    /**
     * Same resilience for the "Reactivate" side door on the Landlords list
     * when the account still needs email verification first.
     */
    public function test_reactivating_an_unverified_landlord_still_records_approval_if_the_email_cannot_be_sent(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::factory()->unverified()->create(['status' => 'inactive']);
        $landlord->assignRole('landlord');

        Mail::shouldReceive('to->send')->once()->andThrow(new \RuntimeException('SMTP connection refused'));

        Livewire::actingAs($admin)
            ->test(LandlordsIndex::class)
            ->call('updateStatus', $landlord->id, 'active');

        $landlord->refresh();
        $this->assertSame('inactive', $landlord->status);
        $this->assertNotNull($landlord->approved_at);
    }

    /**
     * The "same applicant twice" duplicate signal must catch a pair that
     * lands on different pagination pages, not just matches within the
     * same 15-row page.
     */
    public function test_duplicate_signal_is_detected_across_pagination_pages(): void
    {
        $this->seed();

        $sharedPhone = '0700111222';

        // Clearly the oldest of the bunch, so it alone sorts onto page two
        // (latest() orders newest-first, page size 15).
        $first = User::factory()->create(['status' => 'inactive', 'phone' => $sharedPhone, 'created_at' => now()->subDays(2)]);
        $first->assignRole('landlord');

        // 14 unrelated applicants, all newer than $first, fill the rest of
        // page one alongside $second below.
        User::factory()->count(14)->create(['status' => 'inactive', 'created_at' => now()])->each(function (User $u) {
            $u->assignRole('landlord');
        });

        $second = User::factory()->create(['status' => 'inactive', 'phone' => $sharedPhone, 'created_at' => now()]);
        $second->assignRole('landlord');

        $component = Livewire::actingAs(User::role('admin')->firstOrFail())->test(Applications::class);

        $signals = $component->viewData('duplicateSignalFor');

        $this->assertNotNull($signals[$second->id] ?? null, 'the newest applicant (page one) should flag the older match now sitting on page two');
    }
}
