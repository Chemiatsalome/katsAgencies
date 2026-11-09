<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileEmailSubmissions;
use App\Livewire\Admin\Notifications\Index as AdminNotificationsIndex;
use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Mail\GenericNotificationMail;
use App\Models\AppNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
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
        // G-1B: a missing recipient is a safe pre-submission failure — no
        // claim is ever taken, since nothing was ever going to be attempted.
        $this->assertEquals('failed', $notification->delivery_status);
        $this->assertNull($notification->submission_token);
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

    // --- G-1B: delivery-state machine, claiming, and failure classification ---------------

    public function test_successful_submission_captures_the_provider_message_id(): void
    {
        $tenant = $this->makeTenant('contact-address@example.test', null);

        $sentMessage = \Mockery::mock(SentMessage::class);
        $sentMessage->shouldReceive('getMessageId')->once()->andReturn('resend-provider-id-123');
        $pendingMail = \Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->andReturn($sentMessage);
        Mail::shouldReceive('to')->once()->with('contact-address@example.test')->andReturn($pendingMail);

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        $notification = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)->where('channel', 'email')->first();

        $this->assertEquals('sent', $notification->status);
        $this->assertEquals('sent', $notification->delivery_status);
        $this->assertEquals('resend-provider-id-123', $notification->provider_message_id);
    }

    /** A missing message id must never turn a successful SMTP submission into a failed email. */
    public function test_successful_submission_when_no_message_id_is_returned(): void
    {
        Mail::fake();
        $tenant = $this->makeTenant('contact-address@example.test', null);

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertSent(GenericNotificationMail::class, 1);

        $notification = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)->where('channel', 'email')->first();

        $this->assertEquals('sent', $notification->status);
        $this->assertEquals('sent', $notification->delivery_status);
        $this->assertNull($notification->provider_message_id);
    }

    public function test_locally_detected_missing_smtp_configuration_fails_with_no_provider_call(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);
        $tenant = $this->makeTenant('contact-address@example.test', null);

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        Mail::assertNothingSent();

        $notification = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)->where('channel', 'email')->first();

        $this->assertEquals('failed', $notification->status);
        $this->assertEquals('failed', $notification->delivery_status);
        $this->assertNull($notification->submission_token, 'A claim must never be taken for a send that was never attempted.');
    }

    /**
     * An UnexpectedResponseException carrying a real, nonzero SMTP
     * response code (RFC 5321's own definite-negative-reply convention)
     * is the one exception shape this design treats as a confirmed
     * rejection — see NotificationService::classifyMailException().
     */
    public function test_explicit_smtp_rejection_with_a_response_code_is_classified_as_failed(): void
    {
        $tenant = $this->makeTenant('contact-address@example.test', null);

        $pendingMail = \Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->andThrow(new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.1.1 Recipient address rejected."', 550));
        Mail::shouldReceive('to')->once()->with('contact-address@example.test')->andReturn($pendingMail);

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        $notification = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)->where('channel', 'email')->first();

        $this->assertEquals('failed', $notification->status);
        $this->assertEquals('failed', $notification->delivery_status);
    }

    /**
     * Any exception without a usable SMTP response code — here, a
     * connection-level TransportException with no code at all — must
     * never be classified as a safe, confirmed failure. This is the
     * email-channel equivalent of G-1A's finding that Guzzle/cURL's own
     * error classification can't reliably tell "never reached the
     * provider" apart from "reached it, response lost."
     */
    public function test_ambiguous_smtp_exception_is_classified_as_submission_unknown_not_failed(): void
    {
        $tenant = $this->makeTenant('contact-address@example.test', null);

        $pendingMail = \Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->andThrow(new TransportException('Connection reset by peer.'));
        Mail::shouldReceive('to')->once()->with('contact-address@example.test')->andReturn($pendingMail);

        app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

        $notification = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)->where('channel', 'email')->first();

        $this->assertEquals('submission_unknown', $notification->delivery_status);
        // status's own meaning is unchanged by G-1B — still 'pending',
        // which keeps this correctly surfaced by scopeNeedsAttention().
        $this->assertEquals('pending', $notification->status);
    }

    /**
     * Proves the atomic claim itself, the same way G-1A's SMS claim test
     * does: once a notification is past 'pending', a second claim attempt
     * must affect 0 rows and never reach the provider. This is a real DB
     * test of the conditional UPDATE's logical correctness — sequential,
     * not two genuinely simultaneous connections (this suite runs on a
     * single SQLite :memory: connection, same caveat as G-1A).
     */
    public function test_a_second_claim_attempt_on_an_already_claimed_email_notification_is_rejected(): void
    {
        $tenant = $this->makeTenant('contact-address@example.test', null);

        $notification = AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'email', 'event_type' => 'test_event', 'title' => 'Title', 'body' => 'Body',
            'status' => 'pending', 'delivery_status' => 'pending',
        ]);

        $firstClaim = AppNotification::where('id', $notification->id)->where('delivery_status', 'pending')
            ->update(['delivery_status' => 'submitting', 'submission_token' => 'first-token', 'submission_started_at' => now(), 'submission_attempt' => 1]);
        $secondClaimAttempt = AppNotification::where('id', $notification->id)->where('delivery_status', 'pending')
            ->update(['delivery_status' => 'submitting', 'submission_token' => 'second-token', 'submission_started_at' => now(), 'submission_attempt' => 1]);

        $this->assertEquals(1, $firstClaim, 'The first claim must succeed.');
        $this->assertEquals(0, $secondClaimAttempt, 'A second claim against an already-claimed row must affect 0 rows.');
        $this->assertEquals('first-token', $notification->refresh()->submission_token);
    }

    /** An expired 'submitting' claim must become submission_unknown, never silently return to pending. */
    public function test_expired_email_claim_becomes_submission_unknown_never_back_to_pending(): void
    {
        $tenant = $this->makeTenant('contact-address@example.test', null);
        $notification = AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'email', 'event_type' => 'test_event', 'title' => 'Title', 'body' => 'Body',
            'status' => 'pending', 'delivery_status' => 'submitting',
            'submission_token' => 'stale-token', 'submission_started_at' => now()->subMinutes(20), 'submission_attempt' => 1,
        ]);

        $this->artisan(ReconcileEmailSubmissions::class)->assertSuccessful();

        $this->assertEquals('submission_unknown', $notification->refresh()->delivery_status);
    }

    /**
     * Once the reconciliation sweep has expired and cleared a stale
     * claim's token, the original (now stale) worker must never be able
     * to overwrite that outcome just because its own SMTP call eventually
     * returns — its token no longer matches the row.
     */
    public function test_a_stale_worker_cannot_overwrite_a_claim_the_reconciliation_sweep_already_expired(): void
    {
        $tenant = $this->makeTenant('contact-address@example.test', null);
        $notification = AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'email', 'event_type' => 'test_event', 'title' => 'Title', 'body' => 'Body',
            'status' => 'pending', 'delivery_status' => 'submitting',
            'submission_token' => 'original-worker-token', 'submission_started_at' => now()->subMinutes(20), 'submission_attempt' => 1,
        ]);

        $this->artisan(ReconcileEmailSubmissions::class)->assertSuccessful();
        $this->assertEquals('submission_unknown', $notification->refresh()->delivery_status);

        $staleWorkerUpdate = AppNotification::where('id', $notification->id)->where('submission_token', 'original-worker-token')
            ->update(['status' => 'sent', 'sent_at' => now(), 'delivery_status' => 'sent']);

        $this->assertEquals(0, $staleWorkerUpdate, 'The stale worker\'s token no longer matches — its update must affect 0 rows.');
        $this->assertEquals('submission_unknown', $notification->refresh()->delivery_status);
    }

    public function test_admin_notifications_page_filters_and_counts_unresolved_email(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $tenant = $this->makeTenant('contact-address@example.test', null);

        // Distinguished by event_type, not title — the table renders
        // event_type (formatted), never the notification's own title.
        AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'email', 'event_type' => 'unresolved_event_marker', 'title' => 'Unresolved One', 'body' => 'Body',
            'status' => 'pending', 'delivery_status' => 'submission_unknown', 'provider_message_id' => null,
        ]);
        AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'email', 'event_type' => 'resolved_event_marker', 'title' => 'Resolved Sent', 'body' => 'Body',
            'status' => 'sent', 'delivery_status' => 'sent', 'provider_message_id' => 'some-id',
        ]);

        Livewire::actingAs($admin)
            ->test(AdminNotificationsIndex::class)
            ->assertSee('Unresolved email only (1)')
            ->set('onlyUnresolvedEmail', true)
            ->assertSee('Unresolved Event Marker')
            ->assertDontSee('Resolved Event Marker');
    }

    /**
     * Documents current, unresolved behavior rather than a passing
     * guarantee: NotificationService::deliver() does not defer email
     * dispatch via DB::afterCommit() (see its own docblock for why —
     * Laravel's DatabaseTransactionsManager never runs afterCommit
     * callbacks under this project's RefreshDatabase-based test harness,
     * since the transaction level never reaches 0 during a test). This
     * test exists to make that limitation visible and regression-checked,
     * not to claim the ideal behavior is implemented.
     */
    public function test_email_currently_sends_synchronously_even_inside_an_open_transaction(): void
    {
        Mail::fake();
        $tenant = $this->makeTenant('contact-address@example.test', null);

        \Illuminate\Support\Facades\DB::transaction(function () use ($tenant) {
            app(NotificationService::class)->notify($tenant, 'test_event', 'Title', 'Body', ['email']);

            // Known limitation: this assertion documents that the send
            // already happened here, synchronously, before this
            // transaction has committed — not the ideal behavior, but the
            // current, honest one. See the docblock above and
            // NotificationService::deliver()'s own comment.
            Mail::assertSent(GenericNotificationMail::class, 1);
        });
    }
}
