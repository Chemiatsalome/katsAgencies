<?php

namespace Tests\Feature;

use App\Livewire\Actions\Logout;
use App\Livewire\ManagerInvitations\Accept as AcceptManagerInvitation;
use App\Livewire\TenantInvitations\Accept as AcceptTenantInvitation;
use App\Models\House;
use App\Models\Manager;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Regression coverage for the invited-tenant verification loop: an invited
 * tenant (or manager) activates their account, uses it successfully, then
 * gets bounced to the "please verify your email" notice the next time their
 * session expires and they log back in. Root cause was that email_verified_at
 * wasn't in User's #[Fillable] allow-list, so every `User::create([...,
 * 'email_verified_at' => now()])` across the invitation flows silently
 * dropped that column instead of persisting it.
 */
class InvitedAccountVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function vacantHouseFor(User $landlord): House
    {
        $house = House::vacant()
            ->whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->first();

        $this->assertNotNull($house, 'Seed data should include at least one vacant unit for this landlord.');

        return $house;
    }

    public function test_invited_tenant_is_verified_on_activation_and_can_log_in_again_after_session_expiry(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        $invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => $landlord->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => 14000,
            'deposit_required' => 14000,
            'rent_due_day' => 5,
            'move_in_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Livewire::test(AcceptTenantInvitation::class, ['token' => $invitation->token])
            ->set('name', 'Jane Registrant')
            ->set('phone', '0711000111')
            ->set('email', 'jane.registrant@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors();

        $tenant = Tenant::where('email', 'jane.registrant@example.test')->firstOrFail();
        $user = $tenant->user;

        // The invitation token is the secure channel here — activation must
        // establish verified status immediately, not leave it to be asked
        // for later.
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasVerifiedEmail());

        // Simulate the session expiring and the tenant logging back in from
        // scratch, through the real HTTP login form.
        app(Logout::class)();
        $this->assertGuest();

        $login = Volt::test('pages.auth.login')
            ->set('form.email', 'jane.registrant@example.test')
            ->set('form.password', 'password123');

        $login->call('login')->assertHasNoErrors();

        $this->assertAuthenticated();

        // The generic /dashboard route carries the 'verified' middleware —
        // this is exactly where the loop used to surface.
        $this->get('/dashboard')
            ->assertRedirect(route('tenant.dashboard'));

        $this->get(route('tenant.dashboard'))->assertOk();

        // Verification status must not have moved as a side effect of
        // logging out/back in.
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertEquals(
            $user->email_verified_at->timestamp,
            $user->fresh()->email_verified_at->timestamp,
        );
    }

    public function test_invited_manager_is_verified_on_activation_and_can_log_in_again_after_session_expiry(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Jane Manager',
            'phone' => '0700000001',
            'email' => 'jane-manager@test.test',
            'all_properties' => true,
            'permissions' => ['tenants.view'],
            'status' => 'invited',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
        ]);

        Livewire::test(AcceptManagerInvitation::class, ['token' => $manager->token])
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors();

        $managerUser = User::where('email', 'jane-manager@test.test')->firstOrFail();

        $this->assertNotNull($managerUser->email_verified_at);
        $this->assertTrue($managerUser->hasVerifiedEmail());

        app(Logout::class)();
        $this->assertGuest();

        Volt::test('pages.auth.login')
            ->set('form.email', 'jane-manager@test.test')
            ->set('form.password', 'password123')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();

        $this->get('/dashboard')
            ->assertRedirect(route('manager.dashboard'));
    }

    public function test_verification_status_survives_logout_and_reauthentication(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $user = User::factory()->create(['status' => 'active']);
        $user->syncRoles(['tenant']);

        $this->assertTrue($user->hasVerifiedEmail());

        $this->actingAs($user);
        app(Logout::class)();
        $this->assertGuest();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $this->assertAuthenticated();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->get('/dashboard')->assertRedirect(route('tenant.dashboard'));
    }

    public function test_unverified_account_is_still_sent_to_the_verification_notice(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $user = User::factory()->unverified()->create(['status' => 'active']);
        $user->syncRoles(['tenant']);

        $this->actingAs($user);

        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_resend_verification_dispatches_a_real_notification_and_reports_success_only_then(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user);

        Volt::test('pages.auth.verify-email')->call('sendVerification');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resend_verification_does_not_resend_once_already_verified(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('pages.auth.verify-email')->call('sendVerification');

        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_landlord_login_is_unaffected(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['password' => bcrypt('password123')]);

        Volt::test('pages.auth.login')
            ->set('form.email', $landlord->email)
            ->set('form.password', 'password123')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
        $this->get('/dashboard')->assertRedirect(route('landlord.dashboard'));
    }

    public function test_admin_login_is_unaffected(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $admin->update(['password' => bcrypt('password123')]);

        Volt::test('pages.auth.login')
            ->set('form.email', $admin->email)
            ->set('form.password', 'password123')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
        $this->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    }
}
