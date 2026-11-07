<?php

namespace App\Console\Commands;

use App\Models\MpesaTransaction;
use App\Services\MpesaInvestigationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Closes the one real reconciliation gap in the whole M-Pesa flow: a
 * transaction that never got a callback at all (Safaricom's POST lost,
 * the server unreachable at that instant, etc.) sat 'pending' forever —
 * the client-side 3-minute timeout in PayRent/PayDeposit::checkStatus()
 * only gives up locally, it never actually finds out what happened. This
 * asks Safaricom directly via the STK status query API.
 *
 * Phase 4 audit, Finding 4: no longer writes a terminal 'timeout' status —
 * a transaction that gets no answer within the first 2 minutes, nor any
 * definitive one by 10, is escalated for manual verification instead
 * (status stays 'pending', see MpesaTransaction::isFinished()'s docblock).
 * It may still resolve later from a genuinely late callback, a later query,
 * or an admin's own investigation. The actual query/interpret/apply logic
 * now lives in MpesaInvestigationService::queryAndInterpret(), shared with
 * the admin "re-query" action and a tenant's cancellation request.
 */
#[Signature('mpesa:reconcile-pending')]
#[Description('Query Safaricom for any M-Pesa transaction that\'s been pending for a while with no callback received, and resolve or escalate it.')]
class ReconcileStuckMpesaTransactions extends Command
{
    public function handle(MpesaInvestigationService $investigation): int
    {
        $stuck = MpesaTransaction::unresolved()
            ->whereNotNull('checkout_request_id')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->get();

        $resolved = 0;
        $escalated = 0;

        foreach ($stuck as $transaction) {
            $query = $investigation->queryAndInterpret($transaction, 'reconciliation');

            if (in_array($query->interpreted_as, ['sms_topup_credited', 'provider_result_applied'], true)) {
                $resolved++;

                continue;
            }

            if ($query->interpreted_as === 'escalated_manual_verification') {
                $escalated++;

                continue;
            }

            // Inconclusive (no answer at all) — give up waiting for a quick
            // resolution after a longer window and escalate it too, same
            // reasoning the client-side timeout already used, but without
            // ever closing the transaction out.
            if ($transaction->created_at->lt(now()->subMinutes(10))) {
                $investigation->escalateForManualVerification(
                    $transaction,
                    null,
                    "Safaricom has not confirmed what happened to this payment after repeated status queries over 10+ minutes — needs manual verification against the tenant's M-Pesa statement, then recording via Record Payment if it did go through.",
                );
                $escalated++;
            }
        }

        $this->info("Reconciled {$resolved}, escalated {$escalated} for manual verification.");

        return self::SUCCESS;
    }
}
