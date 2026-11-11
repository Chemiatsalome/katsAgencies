<?php

namespace Tests\Feature;

use App\Livewire\Admin\Landlords\Applications;
use App\Livewire\Admin\Landlords\Index as LandlordsIndex;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Mail\LandlordApplicationApprovedMail;
use App\Mail\LandlordApplicationApprovedPendingVerificationMail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Admin approval and email verification are independent gates for a
 * landlord (see User::isAwaitingEmailVerification() and
 * Applications::approve()) — these tests exercise both orders they can
 * complete in, plus the regression cases for roles that were never
 * supposed to be affected by that gating at all.
 */
class LandlordOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function verificationUrlFor(User $user, ?\DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            $expires ?? now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    }

    public function test_registering_as_a_landlord_creates_a_pending_account_and_redirects_to_holding_page(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $component = Volt::test('pages.auth.register')
            ->set('name', 'New Landlord')
            ->set('email', 'newlandlord@example.test')
            ->set('phone', '0712345678')
            ->set('national_id', '12345678')
            ->set('property_location', 'Nyali, Mombasa')
            ->set('intended_unit_count', '5')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123');

        $component->call('register')->assertRedirect(route('pending-approval', absolute: false));

        $user = User::where('email', 'newlandlord@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('landlord'));
        $this->assertEquals('inactive', $user->status);
        $this->assertTrue($user->isPendingApproval());

        // A pending landlord is redirected away from their dashboard until approved.
        $this->actingAs($user)->get('/landlord/dashboard')->assertRedirect(route('pending-approval', absolute: false));
    }

    /**
     * Regression test for a real bug: AppServiceProvider used to
     * re-register Registered -> SendEmailVerificationNotification itself,
     * on top of the identical mapping Laravel's framework already wires up
     * by default (Illuminate\Foundation\Application always boots its own
     * internal base EventServiceProvider, independently of
     * bootstrap/providers.php — see configureEmailVerification() there).
     * That meant two listener instances handled the one Registered event
     * fired per signup, so every new landlord got two verification emails,
     * each with its own now()-based expiry and signature. Exactly one
     * notification per registration is the behaviour being pinned here.
     */
    public function test_registering_as_a_landlord_sends_exactly_one_verification_email(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        Notification::fake();

        Volt::test('pages.auth.register')
            ->set('name', 'New Landlord')
            ->set('email', 'onlyoneemail@example.test')
            ->set('phone', '0712345678')
            ->set('national_id', '12345678')
            ->set('property_location', 'Nyali, Mombasa')
            ->set('intended_unit_count', '5')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $user = User::where('email', 'onlyoneemail@example.test')->firstOrFail();

        Notification::assertSentTimes(VerifyEmail::class, 1);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * Scenario: landlord attempts login before admin approval, regardless
     * of whether they've verified their email — isPendingApproval() only
     * cares that no admin decision has happened yet, so they land on
     * pending-approval either way, not the verification flow.
     */
    public function test_a_landlord_awaiting_first_review_lands_on_pending_approval_even_if_already_verified(): void
    {
        $this->seed(RoleSeeder::class);

        $applicant = User::factory()->create(['status' => 'inactive']); // verified by factory default
        $applicant->assignRole('landlord');

        $this->assertTrue($applicant->isPendingApproval());
        $this->assertFalse($applicant->isAwaitingEmailVerification());

        $this->actingAs($applicant)->get('/landlord/dashboard')
            ->assertRedirect(route('pending-approval', absolute: false));
    }

    /**
     * Scenario 5: both approval and verification already satisfied —
     * approving activates immediately and sends the "you can log in" email,
     * never the pending-verification one.
     */
    public function test_approving_an_already_verified_landlord_activates_immediately(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $applicant = User::factory()->create(['status' => 'inactive']); // verified by factory default
        $applicant->assignRole('landlord');

        Mail::fake();

        Livewire::actingAs($admin)->test(Applications::class)->call('approve', $applicant->id);

        $applicant->refresh();
        $this->assertSame('active', $applicant->status);
        $this->assertNotNull($applicant->approved_at);

        Mail::assertSent(LandlordApplicationApprovedMail::class);
        Mail::assertNotSent(LandlordApplicationApprovedPendingVerificationMail::class);

        $this->actingAs($applicant)->get('/landlord/dashboard')->assertOk();
    }

    /**
     * Scenario 2: admin approves before the landlord has verified their
     * email. Approval is recorded, but activation — and the dashboard — are
     * withheld until they verify, at which point ActivateApprovedLandlordOnVerification
     * flips them active with no further admin action.
     */
    public function test_approving_an_unverified_landlord_defers_activation_until_they_verify(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $applicant = User::factory()->unverified()->create(['status' => 'inactive']);
        $applicant->assignRole('landlord');

        Mail::fake();

        Livewire::actingAs($admin)->test(Applications::class)->call('approve', $applicant->id);

        $applicant->refresh();
        $this->assertSame('inactive', $applicant->status, 'must not activate before the email is verified');
        $this->assertNotNull($applicant->approved_at, 'the approval itself must still be recorded');
        $this->assertTrue($applicant->isAwaitingEmailVerification());

        Mail::assertSent(LandlordApplicationApprovedPendingVerificationMail::class);
        Mail::assertNotSent(LandlordApplicationApprovedMail::class);

        // Login is allowed (this isn't a credential failure) but every
        // protected page bounces to the verification flow specifically —
        // not the generic "awaiting review" page, which would now be wrong.
        $this->actingAs($applicant)->get('/landlord/dashboard')
            ->assertRedirect(route('verification.notice', absolute: false));

        $this->actingAs($applicant)->get($this->verificationUrlFor($applicant));

        $applicant->refresh();
        $this->assertSame('active', $applicant->status);
        $this->assertFalse($applicant->isAwaitingEmailVerification());

        $this->actingAs($applicant)->get('/landlord/dashboard')->assertOk();
    }

    /**
     * Scenario 1: landlord verifies before admin ever reviews the
     * application. Verifying alone must not activate an unreviewed
     * account — only once the admin subsequently approves does it go
     * active, immediately (no second "pending verification" detour).
     */
    public function test_a_landlord_who_verifies_before_admin_approval_stays_pending_until_approved_then_activates_immediately(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $applicant = User::factory()->unverified()->create(['status' => 'inactive']);
        $applicant->assignRole('landlord');

        $this->actingAs($applicant)->get($this->verificationUrlFor($applicant));

        $applicant->refresh();
        $this->assertTrue($applicant->hasVerifiedEmail());
        $this->assertSame('inactive', $applicant->status, 'verifying alone must not activate an unreviewed account');
        $this->assertTrue($applicant->isPendingApproval());

        // Still correctly routed to pending-approval, not verify-email,
        // since there's nothing left to verify but nothing approved yet.
        $this->actingAs($applicant)->get('/landlord/dashboard')
            ->assertRedirect(route('pending-approval', absolute: false));

        Mail::fake();
        Livewire::actingAs($admin)->test(Applications::class)->call('approve', $applicant->id);

        $applicant->refresh();
        $this->assertSame('active', $applicant->status);
        Mail::assertSent(LandlordApplicationApprovedMail::class);
        Mail::assertNotSent(LandlordApplicationApprovedPendingVerificationMail::class);

        $this->actingAs($applicant)->get('/landlord/dashboard')->assertOk();
    }

    /**
     * Scenarios 6 & 7: once active and verified, logging out and back in
     * (or an expired session doing the same thing from the server's point
     * of view — a fresh, unauthenticated request) must never re-trigger a
     * verification prompt. email_verified_at/approved_at are durable
     * account state, not session state.
     */
    public function test_a_verified_active_landlord_can_log_back_in_without_any_reverification_prompt(): void
    {
        $this->seed();

        $landlord = User::factory()->create(['status' => 'active']); // verified by factory default
        $landlord->assignRole('landlord');
        $landlord->forceFill(['approved_at' => now()])->save();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $landlord->email)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $this->get('/landlord/dashboard')->assertOk();
    }

    /**
     * Scenario 8: an expired link is correctly rejected (not silently
     * accepted), and resending produces a link that works — without the
     * earlier, already-expired one having done anything destructive to the
     * account in the meantime.
     */
    public function test_an_expired_verification_link_is_rejected_and_resending_produces_a_working_one(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->unverified()->create();

        $expiredUrl = $this->verificationUrlFor($user, now()->subMinute());

        $this->actingAs($user)->get($expiredUrl)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->actingAs($user);
        Volt::test('pages.auth.verify-email')->call('sendVerification');

        $this->actingAs($user)->get($this->verificationUrlFor($user));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /**
     * Scenario 9 (regression): tenants were never part of this gating and
     * must keep logging straight into their own dashboard.
     */
    public function test_an_existing_tenant_logs_in_and_reaches_their_own_dashboard(): void
    {
        $this->seed(RoleSeeder::class);

        $tenant = User::factory()->create(['status' => 'active']);
        $tenant->assignRole('tenant');

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $tenant->email)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->get('/tenant/dashboard')->assertOk();
    }

    /**
     * Scenario 10 (regression): administrators were never part of this
     * gating either.
     */
    public function test_an_administrator_logs_in_and_reaches_their_dashboard(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $admin->email)
            ->set('form.password', 'password')
            ->call('login');

        $component->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->get('/admin/dashboard')->assertOk();
    }

    /**
     * The "Reactivate" control on the Landlords list is a second path to
     * the same `status = active` flip Applications::approve() guards — it
     * must defer activation the same way, not bypass the gate.
     */
    public function test_reactivating_an_unverified_landlord_via_the_landlords_index_defers_activation_too(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::factory()->unverified()->create(['status' => 'inactive']);
        $landlord->assignRole('landlord');
        $landlord->forceFill(['approved_at' => now()->subDay()])->save(); // previously approved, since deactivated

        Mail::fake();

        Livewire::actingAs($admin)
            ->test(LandlordsIndex::class)
            ->call('updateStatus', $landlord->id, 'active');

        $landlord->refresh();
        $this->assertSame('inactive', $landlord->status);
        Mail::assertSent(LandlordApplicationApprovedPendingVerificationMail::class);

        $this->actingAs($landlord)->get($this->verificationUrlFor($landlord));

        $this->assertSame('active', $landlord->fresh()->status);
    }

    /**
     * The general-purpose admin Users page reaches every account type and
     * must not be a third side door around the same gate.
     */
    public function test_activating_an_unverified_landlord_via_the_general_users_page_also_defers_activation(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::factory()->unverified()->create(['status' => 'inactive']);
        $landlord->assignRole('landlord');

        Mail::fake();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateStatus', $landlord->id, 'active');

        $landlord->refresh();
        $this->assertSame('inactive', $landlord->status);
        $this->assertNotNull($landlord->approved_at);
        Mail::assertSent(LandlordApplicationApprovedPendingVerificationMail::class);
    }

    public function test_a_suspended_user_is_logged_out_on_next_request(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['status' => 'suspended']);

        $this->actingAs($landlord)->get('/landlord/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
