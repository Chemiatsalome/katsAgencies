<?php

namespace App\Services;

use App\Mail\GenericNotificationMail;
use App\Models\AppNotification;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * @param  Model  $notifiable  Typically a Tenant or User — must expose ->email for the email channel.
     * @param  string[]  $channels  Any of: in_app, email, sms.
     */
    public function notify(
        Model $notifiable,
        string $eventType,
        string $title,
        string $body,
        array $channels = ['in_app'],
        ?Model $related = null,
    ): void {
        foreach ($channels as $channel) {
            $notification = AppNotification::create([
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'channel' => $channel,
                'event_type' => $eventType,
                'title' => $title,
                'body' => $body,
                'status' => 'pending',
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
            ]);

            $this->deliver($notification, $notifiable);
        }
    }

    /**
     * Marks the originating alert read once whoever it was for has acted on
     * it — a decision, a reply, a status change — so the generic
     * notification bell stops counting something already resolved.
     * Filtered by notifiable as well as the related record, since several
     * event types can share the same related model (e.g. a tenancy) from
     * both directions — without that filter, resolving one party's alert
     * would also silently mark the other party's still-unread notification
     * as read. $eventType narrows further for a related model that's shared
     * across many unrelated event types (e.g. Tenancy itself).
     */
    public function markRelatedRead(Model $related, Model $notifiable, ?string $eventType = null): void
    {
        AppNotification::where('related_type', $related->getMorphClass())
            ->where('related_id', $related->getKey())
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->when($eventType, fn ($q) => $q->where('event_type', $eventType))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function deliver(AppNotification $notification, Model $notifiable): void
    {
        match ($notification->channel) {
            'in_app' => $notification->update(['status' => 'sent', 'sent_at' => now()]),
            'email' => $this->deliverEmail($notification, $notifiable),
            'sms' => $this->deliverSms($notification, $notifiable),
            default => null,
        };
    }

    private function deliverEmail(AppNotification $notification, Model $notifiable): void
    {
        $email = $this->resolveEmailAddress($notifiable);

        if (! $email) {
            $notification->update(['status' => 'failed']);

            return;
        }

        try {
            Mail::to($email)->send(new GenericNotificationMail($notification->title, $notification->body));
            $notification->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Notification email failed', ['notification_id' => $notification->id, 'error' => $e->getMessage()]);
            $notification->update(['status' => 'failed']);
        }
    }

    /**
     * Phase 7B follow-up: tenants.email is an optional, landlord-entered
     * contact field, entirely separate from — and never synced with — the
     * tenant's actual portal-login email (tenants.user.email, set only by
     * TenancyProvisioningService::createPortalAccount()). Reading
     * $notifiable->email directly meant a tenant with working portal access
     * but no separately-entered contact email silently received zero
     * email-channel notifications, ever. Scoped strictly to Tenant — every
     * other notifiable type (User, covering landlords/managers/admins)
     * resolves exactly as before, unchanged.
     */
    private function resolveEmailAddress(Model $notifiable): ?string
    {
        if (! $notifiable instanceof Tenant) {
            return $notifiable->email ?? null;
        }

        return $this->usableEmail($notifiable->email) ?? $this->usableEmail($notifiable->user?->email);
    }

    /** Null for blank/whitespace-only/malformed input — never attempts to send to something that isn't a real address. */
    private function usableEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function deliverSms(AppNotification $notification, Model $notifiable): void
    {
        $phone = $notifiable->phone ?? null;

        if (! $phone) {
            $notification->update(['status' => 'failed']);

            return;
        }

        $landlordId = $this->resolveBillableLandlordId($notifiable);

        if ($landlordId !== null) {
            $wallet = app(SmsWalletService::class);

            // Cheap pre-check before even attempting a send — an empty
            // wallet means SMS just silently stops (in-app/email, if also
            // selected, are unaffected — each channel is its own
            // AppNotification row via the loop in notify() above). The
            // landlord sees their balance in Settings; the tenant is never
            // told a text didn't arrive over a billing issue that isn't theirs.
            if ($wallet->balance($landlordId) < $wallet->rate()) {
                $notification->update(['status' => 'failed']);

                return;
            }
        }

        // The title is dropped here on purpose — unlike email/in-app, an
        // SMS has no separate subject line, and prepending it would just
        // eat into the 160-character budget for no benefit.
        $sent = app(SmsService::class)->send($phone, $notification->body);

        // Only debit for a message that actually went out — never charge
        // for a failed/rejected send, so there's no refund case to handle.
        if ($sent && $landlordId !== null) {
            app(SmsWalletService::class)->debit($landlordId, app(SmsWalletService::class)->rate(), $notification);
        }

        $notification->update($sent
            ? ['status' => 'sent', 'sent_at' => now()]
            : ['status' => 'failed']
        );
    }

    /**
     * Which landlord's SMS wallet pays for this message — only a Tenant
     * notifiable is billable today, since rent reminders/late-fee notices
     * are the only sms-channel senders in the app. Anything else (e.g. a
     * notification to a landlord/admin/manager themselves) isn't billed.
     */
    private function resolveBillableLandlordId(Model $notifiable): ?int
    {
        if ($notifiable instanceof Tenant) {
            return $notifiable->currentTenancy()?->house?->block?->property?->landlord_id;
        }

        return null;
    }
}
