<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use InvalidArgumentException;

/**
 * The landlord → admin escalation workflow that didn't exist anywhere in
 * the app — a landlord's only recourse on a payment/billing/technical/
 * account problem was the generic "contact support" message
 * EnsureAccountIsActive shows reactively. Deliberately simple: one status
 * machine (open → in_progress → resolved, reopenable), one reply thread,
 * no assignment — any admin can act on any ticket, matching how the
 * landlord-application-review workflow already treats "admin" as a single
 * pool rather than individually assigned reviewers.
 */
class SupportTicketService
{
    public function create(User $landlord, string $category, string $subject, string $message): SupportTicket
    {
        if (! in_array($category, SupportTicket::CATEGORIES, true)) {
            throw new InvalidArgumentException('Invalid ticket category.');
        }

        $ticket = SupportTicket::create([
            'landlord_id' => $landlord->id,
            'category' => $category,
            'subject' => $subject,
            'message' => $message,
            'status' => 'open',
        ]);

        foreach (User::role('admin')->get() as $admin) {
            app(NotificationService::class)->notify(
                $admin,
                'support_ticket_submitted',
                'New Support Ticket',
                "{$landlord->name} opened a ticket: \"{$subject}\"",
                ['in_app', 'email'],
                $ticket,
            );
        }

        return $ticket;
    }

    public function reply(SupportTicket $ticket, User $author, string $body): SupportTicketReply
    {
        $reply = SupportTicketReply::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);

        $isAdmin = $author->hasRole('admin');

        // Whoever just replied has, by definition, seen and acted on
        // whatever alerted them — clears only their own copy (if several
        // admins share the pool, the ones who haven't replied yet still
        // see it as unread). Mirrors the same markRelatedRead() pattern
        // every other decision flow uses.
        app(NotificationService::class)->markRelatedRead($ticket, $author);

        if ($isAdmin) {
            // A reply reopens a resolved ticket — the landlord asking a
            // follow-up, or an admin following up after resolving, both
            // mean this isn't actually done yet.
            if ($ticket->status === 'resolved') {
                $ticket->update(['status' => 'in_progress', 'resolved_at' => null, 'resolved_by' => null]);
            } elseif ($ticket->status === 'open') {
                $ticket->update(['status' => 'in_progress']);
            }

            app(NotificationService::class)->notify(
                $ticket->landlord,
                'support_ticket_reply',
                'Reply on Your Support Ticket',
                "An admin replied on \"{$ticket->subject}\": {$body}",
                ['in_app', 'email'],
                $ticket,
            );
        } else {
            if ($ticket->status === 'resolved') {
                $ticket->update(['status' => 'in_progress', 'resolved_at' => null, 'resolved_by' => null]);
            }

            foreach (User::role('admin')->get() as $admin) {
                app(NotificationService::class)->notify(
                    $admin,
                    'support_ticket_reply',
                    'Landlord Replied on a Support Ticket',
                    "{$ticket->landlord->name} replied on \"{$ticket->subject}\": {$body}",
                    ['in_app'],
                    $ticket,
                );
            }
        }

        return $reply;
    }

    public function resolve(SupportTicket $ticket, User $admin, ?string $note = null): void
    {
        if ($note) {
            SupportTicketReply::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $admin->id,
                'body' => $note,
            ]);
        }

        $ticket->update([
            'status' => 'resolved',
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
        ]);

        app(NotificationService::class)->markRelatedRead($ticket, $admin);

        app(NotificationService::class)->notify(
            $ticket->landlord,
            'support_ticket_resolved',
            'Your Support Ticket Was Resolved',
            $note
                ? "Your ticket \"{$ticket->subject}\" has been marked resolved: {$note}"
                : "Your ticket \"{$ticket->subject}\" has been marked resolved.",
            ['in_app', 'email'],
            $ticket,
        );
    }
}
