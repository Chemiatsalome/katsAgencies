<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\LandlordSetting;
use App\Models\RentCharge;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:rent-reminders')]
#[Description('Send configured due-soon and overdue rent reminders to tenants, per each landlord\'s reminder schedule.')]
class SendRentReminders extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        RentCharge::outstanding()
            ->with('tenancy.tenant', 'tenancy.house.block.property')
            ->chunk(200, function ($charges) use ($notifications, $today, &$sent) {
                foreach ($charges as $charge) {
                    $property = $charge->tenancy->house->block->property;
                    $landlordId = $property->landlord_id;

                    $daysBefore = $this->parseDays(LandlordSetting::get($landlordId, 'reminder_days_before', '7,3,1'));
                    $daysAfter = $this->parseDays(LandlordSetting::get($landlordId, 'reminder_days_after', '1,3,7,14,30'));
                    $channels = array_filter(explode(',', (string) LandlordSetting::get($landlordId, 'reminder_channels', 'in_app'))) ?: ['in_app'];

                    $diffDays = (int) $today->diffInDays($charge->due_date, false);

                    $eventType = match (true) {
                        $diffDays > 0 && in_array($diffDays, $daysBefore, true) => "rent_due_soon_{$diffDays}",
                        $diffDays === 0 => 'rent_due_today',
                        $diffDays < 0 && in_array(abs($diffDays), $daysAfter, true) => 'rent_overdue_'.abs($diffDays),
                        default => null,
                    };

                    if (! $eventType) {
                        continue;
                    }

                    $alreadySent = AppNotification::where('related_type', $charge->getMorphClass())
                        ->where('related_id', $charge->id)
                        ->where('event_type', $eventType)
                        ->exists();

                    if ($alreadySent) {
                        continue;
                    }

                    $outstanding = (float) $charge->amount_expected - (float) $charge->amount_paid;
                    $status = $diffDays >= 0 ? 'due' : 'overdue';

                    $notifications->notify(
                        $charge->tenancy->tenant,
                        $eventType,
                        'Rent Payment Reminder',
                        "Your rent of KSh ".number_format($outstanding, 2)." for {$charge->tenancy->house->house_number} is {$status}.",
                        $channels,
                        $charge
                    );

                    $sent++;
                }
            });

        $this->info("Sent {$sent} rent reminder notification(s).");

        return self::SUCCESS;
    }

    /** @return int[] */
    private function parseDays(mixed $raw): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $raw))));
    }
}
