<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * G-1A: the minimum operational reconciliation this design needs — no
 * automated "resolve the unknown outcome" exists, because Africa's Talking
 * gives no confirmed, after-the-fact way to ask what really happened (see
 * the G-1A investigation). This command only ever does two things that are
 * genuinely safe to automate:
 *
 * 1. Expires a `submitting` claim whose worker never reported back within
 *    the lease window — becomes `submission_unknown`, never back to
 *    `pending` (never auto-retried), per the design's explicit rule.
 * 2. Resumes billing (never a provider call) for a notification already
 *    confirmed `sent` whose billing was left `pending` by some earlier
 *    interruption — safe because NotificationService::resume() never
 *    re-contacts the provider for an already-resolved delivery_status.
 *
 * Everything else `needsSmsReconciliation()` surfaces is left for a human
 * with actual evidence — see the admin SMS Wallets page.
 */
#[Signature('notifications:reconcile-sms')]
#[Description('Expire stale SMS submission claims and resume billing for confirmed sends still awaiting it.')]
class ReconcileSmsSubmissions extends Command
{
    private const LEASE_MINUTES = 10;

    public function handle(NotificationService $notifications): int
    {
        // submission_token is cleared, not just the status — a stale
        // worker's own token-guarded write (see NotificationService::
        // deliverSms()) is keyed on that token still matching the row; if
        // it survived here unchanged, a worker whose claim we just expired
        // could still successfully overwrite this outcome once its own
        // (very late) HTTP call finally returns.
        $expired = AppNotification::where('channel', 'sms')
            ->where('delivery_status', 'submitting')
            ->where('submission_started_at', '<', now()->subMinutes(self::LEASE_MINUTES))
            ->update(['delivery_status' => 'submission_unknown', 'billing_status' => 'reconciliation_required', 'submission_token' => null]);

        $resumable = AppNotification::where('channel', 'sms')
            ->where('delivery_status', 'sent')
            ->where('billing_status', 'pending')
            ->get();

        foreach ($resumable as $notification) {
            $notifications->resume($notification);
        }

        $stillNeedsAttention = AppNotification::needsSmsReconciliation()->count();

        $this->info("Expired {$expired} stale claim(s). Resumed billing for {$resumable->count()} notification(s). {$stillNeedsAttention} notification(s) still need manual reconciliation.");

        return self::SUCCESS;
    }
}
