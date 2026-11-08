<?php

namespace Tests\Feature;

use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Mail\GenericNotificationMail;
use App\Models\AppNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 7B follow-up: NotificationService::deliverEmail() used to read
 * $notifiable->email directly — for a Tenant that's tenants.email
 * specifically, an optional, landlord-entered contact field never kept in
 * sync with the tenant's actual portal-login email (tenants.user.email).
 * A tenant with working portal access but a blank tenants.email silently
 * received zero email-channel notifications. Fixed via a Tenant-specific
 * resolveEmailAddress() fallback; every other notifiable type is unchanged.
 * Every test here asserts the actual recipient address the Mailable was
 * sent to, not merely that a notification row exists.
 */
class NotificationServiceEmailResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(?string $tenantEmail, ?string $portalEmail): Tenant
    {
        static $n = 0;
        $n++;

        $tenant = Tenant::create([
            'name' => "Resolution Test Tenant {$n}",
            'phone' => '070000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => $tenantEmail,
            'payment_due_day' => 1,
        ]);

        if ($portalEmail !== null) {
            $user = User::create([
                'name' => $tenant->name,
                'email' => $portalEmail,
                'password' => bcrypt('password'),
                'status' => 'active',
            ]);
            $user->assignRole('tenant');
            $tenant->update(['user_id' => $user->id]);
        }

        return $tenant->fresh();
    }

    public function test_tenant_with_both_addresses_populated_uses_tenants_own_email(): void
    {
        Mail::fake();
        $this->seed();

        $tenant = $this->makeTenant('contact-address@example.test', 'portal-address@example.test');

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->hasTo('contact-address@example.test'));
        Mail::assertSent(GenericNotificationMail::class, 1);
    }

    public function test_tenant_with_blank_tenants_email_falls_back_to_portal_user_email(): void
    {
        Mail::fake();
        $this->seed();

        $tenant = $this->makeTenant('', 'portal-address@example.test');

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->hasTo('portal-address@example.test'));
        Mail::assertSent(GenericNotificationMail::class, 1);
    }

    public function test_tenant_with_null_tenants_email_falls_back_to_portal_user_email(): void
    {
        Mail::fake();
        $this->seed();

        $tenant = $this->makeTenant(null, 'portal-address@example.test');

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->hasTo('portal-address@example.test'));
        Mail::assertSent(GenericNotificationMail::class, 1);
    }

    public function test_tenant_with_neither_usable_address_fails_safely_and_is_recorded_accurately(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant(null, null);

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertNothingSent();

        $notification = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)
            ->where('channel', 'email')
            ->first();

        $this->assertEquals('failed', $notification->status);
    }

    /** A malformed tenants.email must be treated as unusable, not sent to — falls back exactly like a blank one. */
    public function test_tenant_with_a_malformed_tenants_email_falls_back_to_portal_user_email(): void
    {
        Mail::fake();
        $this->seed();

        $tenant = $this->makeTenant('not-an-email-address', 'portal-address@example.test');

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->hasTo('portal-address@example.test'));
    }

    /** Must never double-send — exactly one resolved recipient per notification. */
    public function test_a_tenant_with_both_addresses_never_receives_two_emails(): void
    {
        Mail::fake();
        $this->seed();

        $tenant = $this->makeTenant('contact-address@example.test', 'portal-address@example.test');

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertSent(GenericNotificationMail::class, 1);
        $this->assertEquals(1, AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)->where('channel', 'email')->count());
    }

    /** Resolution for a User notifiable (covers landlords, managers and admins alike — all the same model class) must be exactly as before: no fallback, no Tenant-specific logic applies. */
    public function test_landlord_email_delivery_remains_unchanged(): void
    {
        Mail::fake();
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        app(NotificationService::class)->notify($landlord, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->hasTo($landlord->email));
        Mail::assertSent(GenericNotificationMail::class, 1);
    }

    /** Same resolution path as the landlord test above — User has no Tenant-specific fallback logic, regardless of role. */
    public function test_admin_email_delivery_remains_unchanged(): void
    {
        Mail::fake();
        $this->seed();

        $admin = User::role('admin')->firstOrFail();

        app(NotificationService::class)->notify($admin, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    /**
     * Integration-level: reuses Phase 7B's actual emergency-task-creation
     * flow (Maintenance\Index::createTask()), not a direct service call,
     * with the tenant's own contact email deliberately left blank — proves
     * the fallback reaches a real tenant through the real notification path
     * this phase built, not just the service in isolation.
     */
    public function test_emergency_maintenance_notification_reaches_tenant_portal_email_when_tenants_email_is_blank(): void
    {
        Mail::fake();
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = \App\Models\Tenancy::active()
            ->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->with('house.block.property', 'tenant')
            ->firstOrFail();

        $tenancy->tenant->update(['email' => null]);
        $portalUser = User::create([
            'name' => $tenancy->tenant->name, 'email' => 'blank-contact-portal@example.test',
            'password' => bcrypt('password'), 'status' => 'active',
        ]);
        $portalUser->assignRole('tenant');
        $tenancy->tenant->update(['user_id' => $portalUser->id]);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openTaskForm')
            ->set('task_property_id', $tenancy->house->block->property_id)
            ->set('task_house_id', $tenancy->house_id)
            ->set('task_title', 'Burst water main')
            ->set('task_category', 'water')
            ->set('task_priority', 'emergency')
            ->call('createTask')
            ->assertHasNoErrors();

        Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->hasTo('blank-contact-portal@example.test'));

        $emailNotification = AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
            ->where('notifiable_id', $tenancy->tenant_id)
            ->where('channel', 'email')
            ->where('event_type', 'maintenance_status_submitted')
            ->first();

        $this->assertEquals('sent', $emailNotification->status);
    }

    /** Channels are independent rows — an email-resolution failure must never affect the separate in_app row. */
    public function test_in_app_delivery_is_unaffected_when_email_delivery_fails_for_a_tenant_with_no_usable_address(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant(null, null);

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['in_app', 'email']);

        $inApp = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)->where('channel', 'in_app')->first();
        $email = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)->where('channel', 'email')->first();

        $this->assertEquals('sent', $inApp->status);
        $this->assertEquals('failed', $email->status);
    }
}
