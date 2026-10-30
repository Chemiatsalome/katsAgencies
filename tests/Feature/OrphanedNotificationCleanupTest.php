<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\DueDayChangeRequest;
use App\Models\House;
use App\Models\Tenancy;
use App\Models\TenancyChangeRequest;
use App\Models\TenancyNotice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A real production crash: deleting a Tenant hard-deletes every one of
 * their Tenancy rows (and everything hung off them — notices, change
 * requests, etc.) via FK cascade, but AppNotification's related_type/
 * related_id is a polymorphic reference with no FK of its own, so those
 * rows survived pointing at nothing. AppNotification::actionUrl() then
 * dereferenced the now-missing related row and threw
 * UrlGenerationException the next time ANY page rendered the notification
 * bell — a 500 on the whole landlord dashboard, not just one page. Fixed
 * two ways: actionUrl() degrades to "no link" instead of crashing, and
 * Tenant's deletion hook now cleans up the rows it would otherwise orphan.
 * Kept as a permanent regression test — this is the one place that
 * exercises the actual crash scenario end to end.
 */
class OrphanedNotificationCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Orphan Test Tenant',
            'phone' => '0700444555',
            'payment_due_day' => 1,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    public function test_action_url_returns_null_instead_of_crashing_when_the_related_notice_is_gone(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $notice = TenancyNotice::create([
            'tenancy_id' => $tenancy->id,
            'sequence_number' => 1,
            'issued_by' => $landlord->id,
            'reason_category' => 'other',
            'notice_date' => now()->format('Y-m-d'),
            'status' => 'under_review',
            'tenant_response_type' => 'dispute',
        ]);

        $notification = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(),
            'notifiable_id' => $landlord->id,
            'channel' => 'in_app',
            'event_type' => 'tenancy_notice_dispute_submitted',
            'title' => 'Tenant Disputed a Tenancy Notice',
            'body' => 'test',
            'status' => 'sent',
            'related_type' => TenancyNotice::class,
            'related_id' => $notice->id,
        ]);

        // The notice row is gone, but the notification pointing at it survives.
        $notice->delete();

        $this->assertNull($notification->fresh()->actionUrl($landlord));
    }

    public function test_deleting_a_tenant_cleans_up_notifications_that_would_otherwise_dangle(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenant = $tenancy->tenant;

        $notice = TenancyNotice::create([
            'tenancy_id' => $tenancy->id,
            'sequence_number' => 1,
            'issued_by' => $landlord->id,
            'reason_category' => 'other',
            'notice_date' => now()->format('Y-m-d'),
            'status' => 'issued',
        ]);

        $changeRequest = TenancyChangeRequest::create([
            'tenancy_id' => $tenancy->id,
            'category' => 'other',
            'requested_change' => 'test',
            'status' => 'pending',
        ]);

        $dueDayRequest = DueDayChangeRequest::create([
            'tenancy_id' => $tenancy->id,
            'requested_due_day' => 10,
            'status' => 'pending',
        ]);

        $noticeNotification = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(), 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'tenancy_notice_issued', 'title' => 't', 'body' => 'b', 'status' => 'sent',
            'related_type' => TenancyNotice::class, 'related_id' => $notice->id,
        ]);

        $changeRequestNotification = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(), 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'tenancy_change_request_submitted', 'title' => 't', 'body' => 'b', 'status' => 'sent',
            'related_type' => TenancyChangeRequest::class, 'related_id' => $changeRequest->id,
        ]);

        $dueDayNotification = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(), 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'due_day_change_requested', 'title' => 't', 'body' => 'b', 'status' => 'sent',
            'related_type' => DueDayChangeRequest::class, 'related_id' => $dueDayRequest->id,
        ]);

        $tenancyNotification = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(), 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'tenancy_ended', 'title' => 't', 'body' => 'b', 'status' => 'sent',
            'related_type' => Tenancy::class, 'related_id' => $tenancy->id,
        ]);

        $tenantOwnNotification = AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'in_app', 'event_type' => 'tenancy_notice_issued', 'title' => 't', 'body' => 'b', 'status' => 'sent',
            'related_type' => TenancyNotice::class, 'related_id' => $notice->id,
        ]);

        // A notification completely unrelated to this tenant must survive.
        $unrelatedNotification = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(), 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'landlord_message', 'title' => 't', 'body' => 'b', 'status' => 'sent',
        ]);

        $tenant->delete();

        $this->assertDatabaseMissing('app_notifications', ['id' => $noticeNotification->id]);
        $this->assertDatabaseMissing('app_notifications', ['id' => $changeRequestNotification->id]);
        $this->assertDatabaseMissing('app_notifications', ['id' => $dueDayNotification->id]);
        $this->assertDatabaseMissing('app_notifications', ['id' => $tenancyNotification->id]);
        $this->assertDatabaseMissing('app_notifications', ['id' => $tenantOwnNotification->id]);
        $this->assertDatabaseHas('app_notifications', ['id' => $unrelatedNotification->id]);
    }
}
