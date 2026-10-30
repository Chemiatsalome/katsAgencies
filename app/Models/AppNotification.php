<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

#[Fillable([
    'notifiable_type', 'notifiable_id', 'channel', 'event_type', 'title', 'body',
    'status', 'related_type', 'related_id', 'sent_at', 'read_at',
])]
class AppNotification extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'failed']);
    }

    /**
     * Where clicking this notification should take the viewer — null for
     * most event types, which just display as plain text today. Centralized
     * here (rather than duplicated in the bell dropdown and the full
     * history list) so adding a link for a new event type is a one-line
     * change in one place.
     *
     * Every destination is gated by the *currently authenticated viewer's*
     * role, not by who this notification was originally addressed to
     * ($this->notifiable). Those two routinely differ: a landlord/manager's
     * bell deliberately also surfaces notifications addressed to their
     * tenants (see AppNotification::scopeForLandlordContext()), and the
     * admin's feed is a platform-wide, completely unscoped activity feed
     * (see Admin\NotificationBell/Notifications\Index) — so a tenant-only
     * notification routinely renders in a landlord's or admin's own bell.
     * Handing back a tenant-only (or landlord-only) route in that case sent
     * that viewer straight into a 403, since /tenant/* and /manage/* are
     * mutually exclusive by role. Gating by the viewer degrades to "no
     * link" instead whenever they can't actually reach the real
     * destination, rather than confidently handing back a route that's
     * guaranteed to fail for them.
     *
     * @param  User|null  $viewer  Defaults to the currently authenticated
     *                             user — every real call site (the bell
     *                             dropdown, the full history list) renders
     *                             inside that viewer's own authenticated
     *                             request, so this is correct there without
     *                             passing anything. Pass it explicitly from
     *                             a test, where "whichever user a previous
     *                             Livewire::actingAs() call happened to
     *                             leave active" is not a reliable stand-in
     *                             for "the viewer this assertion means."
     */
    public function actionUrl(?User $viewer = null): ?string
    {
        $viewer ??= Auth::user();
        $viewerIsTenant = (bool) $viewer?->hasRole('tenant');
        $viewerIsLandlordOrManager = (bool) $viewer && ($viewer->hasRole('landlord') || $viewer->hasRole('manager'));
        $viewerIsAdmin = (bool) $viewer?->hasRole('admin');

        // Both of these use a dynamic suffix (days remaining/overdue), so
        // they can't be exact-matched in the switch below — checked first,
        // against the same two recipients (landlord + tenant) the commands
        // that raise them always notify.
        if (str_starts_with($this->event_type, 'contract_expiry_')) {
            return $viewerIsTenant
                ? route('tenant.contract')
                : ($viewerIsLandlordOrManager && $this->related_type === Tenancy::class ? route('manage.tenancies.show', $this->related_id) : null);
        }

        if (str_starts_with($this->event_type, 'rent_due_soon_')
            || $this->event_type === 'rent_due_today'
            || str_starts_with($this->event_type, 'rent_overdue_')) {
            return $viewerIsTenant ? route('tenant.rent') : null;
        }

        if (str_starts_with($this->event_type, 'tenancy_renewal_reminder_')) {
            return $viewerIsTenant
                ? route('tenant.tenancy')
                : ($viewerIsLandlordOrManager && $this->related_type === Tenancy::class ? route('manage.tenancies.show', $this->related_id).'#renewal' : null);
        }

        if (str_starts_with($this->event_type, 'disbursement_overdue_')) {
            return $viewerIsAdmin ? route('admin.disbursements.index') : null;
        }

        return match ($this->event_type) {
            // Admin side.
            'landlord_application_submitted' => $viewerIsAdmin ? route('admin.landlords.applications') : null,
            'disbursement_confirmed', 'disbursement_disputed' => $viewerIsAdmin ? route('admin.disbursements.index') : null,
            'support_ticket_submitted' => $viewerIsAdmin ? route('admin.support-tickets.index') : null,
            'support_ticket_resolved' => $viewerIsLandlordOrManager ? route('manage.support.index') : null,
            // Posted from either side of the same ticket thread — which
            // route fits depends on who's *viewing* it, same reasoning
            // complaint_update_posted/message_reply below use.
            'support_ticket_reply' => match (true) {
                $viewerIsAdmin => route('admin.support-tickets.index'),
                $viewerIsLandlordOrManager => route('manage.support.index'),
                default => null,
            },

            // Landlord/manager side.
            'late_fee_waiver_requested' => $viewerIsLandlordOrManager ? route('manage.arrears.index') : null,
            'disbursement_awaiting_confirmation' => $viewerIsLandlordOrManager ? route('landlord.payouts.index') : null,
            // $this->related is null when the row it points to has since
            // been hard-deleted (e.g. the tenant/tenancy it belonged to was
            // removed) — route() has no fallback for a missing required
            // parameter and throws, so this must degrade to "no link"
            // rather than crash the page the notification bell renders on.
            'due_day_change_requested' => $viewerIsLandlordOrManager && $this->related_type === DueDayChangeRequest::class && $this->related
                ? route('manage.tenancies.show', $this->related->tenancy_id)
                : null,
            'contract_signed', 'tenant_paid_mpesa', 'tenant_registered', 'tenancy_change_request', 'renewal_request', 'mpesa_callback_missed' => $viewerIsLandlordOrManager && $this->related_type === Tenancy::class
                ? route('manage.tenancies.show', $this->related_id)
                : null,
            'tenant_profile_updated' => $viewerIsLandlordOrManager && $this->related_type === Tenant::class
                ? route('manage.tenants.show', $this->related_id)
                : null,
            'manager_registered' => $viewerIsLandlordOrManager && $this->related_type === \App\Models\Manager::class
                ? route('manage.managers.edit', $this->related_id)
                : null,
            'complaint_submitted', 'complaint_confirmed_resolved', 'complaint_reopened' => $viewerIsLandlordOrManager
                ? ($this->related_type === Complaint::class
                    ? route('manage.maintenance.index', ['complaint' => $this->related_id])
                    : route('manage.maintenance.index', ['tab' => 'requests']))
                : null,
            'deposit_deduction_disputed' => $viewerIsLandlordOrManager && $this->related_type === Tenancy::class
                ? route('manage.tenancies.show', $this->related_id).'#deposit'
                : null,
            'tenancy_notice_acknowledged', 'tenancy_notice_dispute_submitted', 'tenancy_notice_time_requested', 'tenancy_notice_extension_requested' => $viewerIsLandlordOrManager && $this->related_type === TenancyNotice::class && $this->related
                ? route('manage.tenancies.show', $this->related->tenancy_id).'#notices'
                : null,
            'tenancy_change_request_submitted' => $viewerIsLandlordOrManager && $this->related_type === TenancyChangeRequest::class && $this->related
                ? route('manage.tenancies.show', $this->related->tenancy_id).'#change-requests'
                : null,
            'tenancy_renewal_requested', 'tenancy_renewal_alternative_responded' => $viewerIsLandlordOrManager && $this->related_type === TenancyRenewalRequest::class && $this->related
                ? route('manage.tenancies.show', $this->related->tenancy_id).'#renewal'
                : null,
            'payment_awaiting_confirmation' => $viewerIsLandlordOrManager && $this->related_type === Payment::class && $this->related
                ? route('manage.tenancies.show', $this->related->tenancy_id).'#payments'
                : null,

            // Tenant side — both land on the page where they'd actually
            // follow up: rent/arrears-related notices go to My Rent, the
            // due-date decision (made on the Contract page) goes back there.
            'late_fee_charged', 'late_fee_waiver_decided', 'rent_reminder', 'landlord_note' => $viewerIsTenant ? route('tenant.rent') : null,
            // Routed straight to the page they'd actually retry from, not
            // just a status page — nothing was charged, so there's nothing
            // to review, only a reason and a way to try again.
            'rent_payment_failed' => $viewerIsTenant ? route('tenant.pay') : null,
            // The tenant's own offline-payment claim changing status —
            // confirmed, disputed, or a correction reopening it — all land
            // back on My Rent, where the claim and its status actually show.
            'payment_confirmed_by_landlord', 'payment_disputed' => $viewerIsTenant ? route('tenant.rent') : null,
            'deposit_requested', 'deposit_deduction_recorded', 'deposit_refunded', 'deposit_payment_confirmed', 'deposit_dispute_resolved', 'deposit_payment_failed' => $viewerIsTenant ? route('tenant.deposit') : null,
            'due_day_change_decided', 'contract_sent' => $viewerIsTenant ? route('tenant.contract') : null,
            'tenancy_notice_issued', 'tenancy_notice_decision_made', 'tenancy_notice_withdrawn', 'tenancy_ended' => $viewerIsTenant ? route('tenant.tenancy') : null,
            'tenancy_change_request_decided', 'tenancy_renewal_decision_made', 'tenancy_renewal_alternative_proposed' => $viewerIsTenant ? route('tenant.tenancy') : null,
            // The bug this whole method was reworked for: this is sent only
            // to the tenant, but surfaces in the landlord's bell too (their
            // tenant's notification) and in admin's platform-wide feed —
            // both previously got handed this same tenant-only route and
            // hit a 403. The landlord already has their own separate,
            // correctly-linked 'tenant_paid_mpesa' notification for the
            // same payment, so there's no landlord destination to add here.
            'payment_confirmed' => $viewerIsTenant ? route('tenant.payments') : null,
            'landlord_message' => $viewerIsTenant ? route('tenant.announcements.index') : null,
            // Posted from either side of the same message thread — which
            // route fits depends on who's *viewing* it, not who it was
            // addressed to.
            'message_reply' => match (true) {
                $viewerIsTenant => route('tenant.announcements.index'),
                $viewerIsLandlordOrManager => route('manage.messages.index'),
                default => null,
            },
            'maintenance_status_reviewed', 'maintenance_status_assigned', 'maintenance_status_scheduled', 'maintenance_status_in_progress', 'maintenance_status_completed', 'maintenance_status_closed' => $viewerIsTenant
                ? ($this->related_type === MaintenanceTask::class && $this->related?->complaint_id
                    ? route('tenant.maintenance.show', $this->related->complaint_id)
                    : route('tenant.maintenance.index'))
                : null,

            // Posted from either side of the same complaint thread — which
            // route fits depends on who's *viewing* it, not the event type.
            'complaint_update_posted' => match (true) {
                $viewerIsTenant => route('tenant.maintenance.show', $this->related_id),
                $viewerIsLandlordOrManager => $this->related_type === Complaint::class
                    ? route('manage.maintenance.index', ['complaint' => $this->related_id])
                    : route('manage.maintenance.index', ['tab' => 'requests']),
                default => null,
            },

            default => null,
        };
    }

    /**
     * Everything a landlord/manager should see in their notification
     * bell/history: notifications about their tenants (rent reminders,
     * messages they sent, etc.) plus notifications addressed to them
     * directly (a tenant's complaint, a signed contract, a renewal
     * request...). Those two were previously queried separately and the
     * landlord-directed half was never surfaced anywhere outside the
     * platform-wide admin view.
     */
    public function scopeForLandlordContext(Builder $query, User $user): Builder
    {
        $tenantIds = Tenant::accessibleBy($user)->pluck('id');

        // landlordId() alone meant a notification addressed to a manager's
        // own User record (e.g. "your access changed") matched neither the
        // landlord's bell (wrong id) nor that manager's own (landlordId()
        // substitutes the landlord's id even when viewing as the manager)
        // — created, but genuinely invisible to everyone. $user->id covers
        // that case; for a landlord it's the same value as landlordId(),
        // so this is purely additive for them.
        $userIds = array_unique(array_filter([$user->id, $user->landlordId()]));

        return $query->where(function (Builder $q) use ($tenantIds, $userIds) {
            $q->where(fn ($q2) => $q2->where('notifiable_type', (new Tenant)->getMorphClass())->whereIn('notifiable_id', $tenantIds))
                ->orWhere(fn ($q2) => $q2->where('notifiable_type', (new User)->getMorphClass())->whereIn('notifiable_id', $userIds));
        });
    }
}
