<?php

namespace App\Services;

use App\Mail\GenericNotificationMail;
use App\Models\AppNotification;
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
        $email = $notifiable->email ?? null;

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

    private function deliverSms(AppNotification $notification, Model $notifiable): void
    {
        $phone = $notifiable->phone ?? null;

        if (! $phone) {
            $notification->update(['status' => 'failed']);

            return;
        }

        // The title is dropped here on purpose — unlike email/in-app, an
        // SMS has no separate subject line, and prepending it would just
        // eat into the 160-character budget for no benefit.
        $sent = app(SmsService::class)->send($phone, $notification->body);

        $notification->update($sent
            ? ['status' => 'sent', 'sent_at' => now()]
            : ['status' => 'failed']
        );
    }
}
