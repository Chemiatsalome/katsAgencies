<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Tenancy;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:contract-expiry')]
#[Description('Notify landlords when a tenancy\'s lease is approaching its end date (60/30/14/7-day marks).')]
class SendContractExpiryAlerts extends Command
{
    private const MILESTONES = [60, 30, 14, 7];

    public function handle(NotificationService $notifications): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        Tenancy::expiringSoon(60)
            ->with('tenant', 'house.block.property.landlord')
            ->get()
            ->each(function (Tenancy $tenancy) use ($notifications, $today, &$sent) {
                $daysRemaining = (int) $today->diffInDays($tenancy->lease_end_date, false);

                if (! in_array($daysRemaining, self::MILESTONES, true)) {
                    return;
                }

                $eventType = "contract_expiry_{$daysRemaining}";

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
                    'Lease Expiring Soon',
                    "{$tenancy->tenant->name}'s lease at {$property->name} — {$tenancy->house->house_number} expires in {$daysRemaining} day(s).",
                    ['in_app'],
                    $tenancy
                );

                $notifications->notify(
                    $tenancy->tenant,
                    $eventType,
                    'Tenancy Agreement Expiring Soon',
                    "Your tenancy agreement expires in {$daysRemaining} days.",
                    ['in_app'],
                    $tenancy
                );

                $sent++;
            });

        $this->info("Sent {$sent} contract-expiry alert(s).");

        return self::SUCCESS;
    }
}
