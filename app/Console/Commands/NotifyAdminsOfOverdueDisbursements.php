<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Disbursements go out on the 10th of each month, but nothing ever told an
 * admin when one was overdue — PendingActionsService had no method for it
 * at all, so a landlord could be waiting on real money with zero proactive
 * signal to anyone who could actually act on it (only an admin records a
 * disbursement; a landlord can't do it themselves).
 */
#[Signature('notifications:disbursements-overdue')]
#[Description('Notify every admin about any landlord whose M-Pesa disbursement is overdue (past the 10th with nothing recorded this cycle).')]
class NotifyAdminsOfOverdueDisbursements extends Command
{
    public function handle(DisbursementService $disbursements, NotificationService $notifications): int
    {
        $releaseDate = $disbursements->mostRecentReleaseDate();

        if (now()->lt($releaseDate)) {
            $this->info('Not past this cycle\'s release date yet — nothing to check.');

            return self::SUCCESS;
        }

        $eventType = 'disbursement_overdue_'.$releaseDate->format('Ym');
        $admins = User::role('admin')->get();
        $sent = 0;

        User::role('landlord')->where('status', 'active')->get()->each(function (User $landlord) use ($disbursements, $notifications, $admins, $eventType, &$sent) {
            if (! $disbursements->isOverdue($landlord->id)) {
                return;
            }

            $alreadySent = AppNotification::where('related_type', $landlord->getMorphClass())
                ->where('related_id', $landlord->id)
                ->where('event_type', $eventType)
                ->exists();

            if ($alreadySent) {
                return;
            }

            $owed = $disbursements->owed($landlord->id);

            foreach ($admins as $admin) {
                $notifications->notify(
                    $admin,
                    $eventType,
                    'Disbursement Overdue',
                    "{$landlord->name} is owed KSh ".number_format($owed, 2)." in collected rent, and this cycle's payout (due the 10th) hasn't been recorded yet.",
                    ['in_app'],
                    $landlord,
                );
            }

            $sent++;
        });

        $this->info("Flagged {$sent} overdue disbursement(s) to ".$admins->count().' admin(s).');

        return self::SUCCESS;
    }
}
