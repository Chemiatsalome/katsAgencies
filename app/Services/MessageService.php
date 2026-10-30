<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;

/**
 * Closes the one real gap between "Messages" (landlord side) and
 * "Announcements" (tenant side) — they were the same one-directional
 * broadcast viewed from two sides of the same AppNotification rows, with
 * no way for either party to actually reply. Message/MessageReply (see
 * Message.php) give each recipient their own thread; this is the one
 * place either side's reply gets recorded and routed to the other.
 */
class MessageService
{
    public function reply(Message $message, User $author, string $body): void
    {
        $reply = $message->replies()->create([
            'user_id' => $author->id,
            'body' => $body,
        ]);

        $isTenantReply = $author->id === $message->tenant->user_id;

        // Whoever just replied has, by definition, seen whatever alerted
        // them to this thread — mirrors the same markRelatedRead() pattern
        // every other decision/reply flow uses.
        app(NotificationService::class)->markRelatedRead($message, $isTenantReply ? $message->tenant : $author);

        if ($isTenantReply) {
            app(NotificationService::class)->notify(
                $message->sender,
                'message_reply',
                'Tenant Replied to Your Message',
                "{$message->tenant->name} replied to \"{$message->subject}\": {$body}",
                ['in_app'],
                $message,
            );
        } else {
            app(NotificationService::class)->notify(
                $message->tenant,
                'message_reply',
                'New Reply From Your Landlord',
                "Your landlord replied to \"{$message->subject}\": {$body}",
                ['in_app'],
                $message,
            );
        }
    }
}
