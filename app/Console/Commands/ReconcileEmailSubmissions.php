<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * G-1B: expires a `submitting` email claim whose worker never reported
 * back within the lease window — becomes `submission_unknown`, never back
 * to `pending` (never auto-resubmitted), the same rule
 * ReconcileSmsSubmissions already applies to SMS. Unlike that command,
 * there is nothing to resume here: email has no billing dimension, so a
 * confirmed `sent` email has nothing further to resolve automatically.
 * Everything this surfaces is left for a human with actual evidence to
 * look up directly with the provider — see
 * Admin\Notifications\Index::$onlyUnresolvedEmail.
 */
#[Signature('notifications:reconcile-email')]
#[Description('Expire stale email submission claims that never reported back.')]
class ReconcileEmailSubmissions extends Command
{
    private const LEASE_MINUTES = 10;

    public function handle(): int
    {
        // submission_token is cleared, not just the status — a stale
        // worker's own token-guarded write (see NotificationService::
        // deliverEmail()) is keyed on that token still matching the row;
        // if it survived here unchanged, a worker whose claim we just
        // expired could still successfully overwrite this outcome once
        // its own (very late) SMTP call finally returns.
        $expired = AppNotification::where('channel', 'email')
            ->where('delivery_status', 'submitting')
            ->where('submission_started_at', '<', now()->subMinutes(self::LEASE_MINUTES))
            ->update(['delivery_status' => 'submission_unknown', 'submission_token' => null]);

        $stillUnresolved = AppNotification::unresolvedEmail()->count();

        $this->info("Expired {$expired} stale claim(s). {$stillUnresolved} email notification(s) still unresolved.");

        return self::SUCCESS;
    }
}
