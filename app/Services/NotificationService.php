<?php

namespace App\Services;

use App\Mail\GenericNotificationMail;
use App\Models\AppNotification;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
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

    /**
     * G-2: fails closed at every step — SmsService::send() is never
     * reached unless a real, billable landlord with a sufficient balance
     * has already been established. The previous version only enforced
     * the balance check when a landlord happened to resolve, which meant
     * any notifiable that resolved to null (a User, or a Tenant with no
     * resolvable tenancy) got a free, unbilled send instead of a refusal.
     */
    private function deliverSms(AppNotification $notification, Model $notifiable): void
    {
        $phone = $notifiable->phone ?? null;

        if (! $phone) {
            $notification->update(['status' => 'failed']);

            return;
        }

        $landlordId = $this->resolveBillableLandlordId($notifiable, $notification->related);

        if ($landlordId === null) {
            $notification->update(['status' => 'failed']);

            return;
        }

        $wallet = app(SmsWalletService::class);
        $rate = $wallet->rate();

        // Cheap pre-check before even attempting a send — an empty wallet
        // means SMS just silently stops (in-app/email, if also selected,
        // are unaffected — each channel is its own AppNotification row via
        // the loop in notify() above). The landlord sees their balance in
        // Settings; the tenant is never told a text didn't arrive over a
        // billing issue that isn't theirs.
        if ($wallet->balance($landlordId) < $rate) {
            $notification->update(['status' => 'failed']);

            return;
        }

        // The title is dropped here on purpose — unlike email/in-app, an
        // SMS has no separate subject line, and prepending it would just
        // eat into the 160-character budget for no benefit.
        $sent = app(SmsService::class)->send($phone, $notification->body);

        if (! $sent) {
            $notification->update(['status' => 'failed']);

            return;
        }

        // debit() re-checks the balance under a row lock — the cheap check
        // above is only a pre-filter to avoid attempting a send we already
        // know can't be billed; a concurrent debit between that check and
        // this one can still legitimately exhaust the balance by now.
        if ($wallet->debit($landlordId, $rate, $notification)) {
            $notification->update(['status' => 'sent', 'sent_at' => now()]);

            return;
        }

        // The SMS already went out, irreversibly, and already cost Kats
        // real money — never re-send it, and never represent it as
        // undelivered just because billing couldn't keep up. Status stays
        // 'sent' (it genuinely was) rather than overloading 'delivered',
        // which is reserved for a real future carrier delivery-receipt
        // feature, not a billing concept. The forced wallet entry is the
        // auditable record of the discrepancy; it may take the landlord's
        // balance negative, which is the honest, reconcilable reflection
        // of a cost already genuinely incurred, not a bug to be hidden.
        $wallet->recordUnbilledSend($landlordId, $rate, $notification);
        $notification->update(['status' => 'sent', 'sent_at' => now()]);
    }

    /**
     * Which landlord's SMS wallet pays for this message.
     *
     * Tenant: the tenancy this notification is actually about — via
     * $related, when it safely identifies one that genuinely belongs to
     * this same tenant — takes priority over the tenant's current
     * tenancy. A reminder about a tenancy that's since ended, or that
     * differs from whichever one happens to be active right now, must
     * still bill the landlord it was actually about; falling through to
     * an unrelated (or absent) current tenancy would silently bypass
     * billing or charge the wrong landlord. $related belonging to a
     * different tenant entirely is never trusted, to avoid inventing an
     * association this notification was never about.
     *
     * Landlord/manager User: User::landlordId() already resolves exactly
     * this — themselves, or their delegating landlord.
     *
     * Admin, any other User landlordId() can't resolve, or any other
     * notifiable type entirely: unbillable, by design — never invented.
     */
    private function resolveBillableLandlordId(Model $notifiable, ?Model $related): ?int
    {
        if ($notifiable instanceof Tenant) {
            $tenancy = $this->resolveRelatedTenancy($related, $notifiable) ?? $notifiable->currentTenancy();

            return $tenancy?->house?->block?->property?->landlord_id;
        }

        if ($notifiable instanceof User) {
            return $notifiable->landlordId();
        }

        return null;
    }

    /**
     * $related can be practically any model across the ~80 notify() call
     * sites in the app (Message, Complaint, DepositDeduction, and so on) —
     * only a Tenancy itself, or something with its own tenancy() relation
     * (e.g. RentCharge, the only kind sms-channel callers currently pass),
     * is ever treated as identifying one; anything else, or a tenancy
     * that resolves but belongs to a different tenant, is ignored rather
     * than guessed at.
     */
    private function resolveRelatedTenancy(?Model $related, Tenant $tenant): ?Tenancy
    {
        $tenancy = match (true) {
            $related instanceof Tenancy => $related,
            $related !== null && method_exists($related, 'tenancy') => $related->tenancy,
            default => null,
        };

        return $tenancy instanceof Tenancy && $tenancy->tenant_id === $tenant->id ? $tenancy : null;
    }
}
