<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Tenancy;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:renewal-reminders')]
#[Description('Notify landlords and tenants when a tenancy\'s renewal review window has opened — fixed-term or open-ended.')]
class SendTenancyRenewalReminders extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $sent = 0;

        Tenancy::whereIn('status', Tenancy::OCCUPYING_STATUSES)
            ->with('tenant', 'house.block.property.landlord')
            ->get()
            ->filter(fn (Tenancy $tenancy) => $tenancy->renewalRelevant())
            ->each(function (Tenancy $tenancy) use ($notifications, &$sent) {
                $dueDate = $tenancy->renewalReviewDueDate();

                if (! $dueDate) {
                    return;
                }

                // One reminder per review-cycle instance, not once per day
                // for the whole window it's relevant — the due date's own
                // month identifies which cycle this is, and it naturally
                // advances to a new value next time around (a later
                // lease_end_date, or the next anniversary once a renewal
                // has actually been approved), so this fires again then
                // without any extra bookkeeping.
                $eventType = 'tenancy_renewal_reminder_'.$dueDate->format('Ym');

                $alreadySent = AppNotification::where('related_type', $tenancy->getMorphClass())
                    ->where('related_id', $tenancy->id)
                    ->where('event_type', $eventType)
                    ->exists();

                if ($alreadySent) {
                    return;
                }

                $property = $tenancy->house->block->property;

                $notifications->notify(
                    $property->landlord,
                    $eventType,
                    'Tenancy Renewal Review Due',
                    "{$tenancy->tenant->name}'s tenancy at {$property->name} — {$tenancy->house->house_number} is due for a renewal review.",
                    ['in_app'],
                    $tenancy,
                );

                $notifications->notify(
                    $tenancy->tenant,
                    $eventType,
                    'Your Tenancy Is Due for Renewal Review',
                    'Your tenancy is approaching its renewal review. You can request a renewal from your My Tenancy page.',
                    ['in_app', 'email'],
                    $tenancy,
                );

                $sent++;
            });

        $this->info("Sent {$sent} tenancy-renewal reminder(s).");

        return self::SUCCESS;
    }
}
