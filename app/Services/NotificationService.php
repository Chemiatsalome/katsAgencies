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

    private function deliver(AppNotification $notification, Model $notifiable): void
    {
        match ($notification->channel) {
            'in_app' => $notification->update(['status' => 'sent', 'sent_at' => now()]),
            'email' => $this->deliverEmail($notification, $notifiable),
            'sms' => $notification->update(['status' => 'pending']),
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
}
