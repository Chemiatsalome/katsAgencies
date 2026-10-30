<?php

namespace App\Console\Commands;

use App\Http\Controllers\MpesaCallbackController;
use App\Models\MpesaTransaction;
use App\Services\NotificationService;
use App\Services\PaymentGatewayService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Closes the one real reconciliation gap in the whole M-Pesa flow: a
 * transaction that never got a callback at all (Safaricom's POST lost,
 * the server unreachable at that instant, etc.) sat 'pending' forever —
 * the client-side 3-minute timeout in PayRent/PayDeposit::checkStatus()
 * only gives up locally, it never actually finds out what happened. This
 * asks Safaricom directly via the STK status query API.
 */
#[Signature('mpesa:reconcile-pending')]
#[Description('Query Safaricom for any M-Pesa transaction that\'s been pending for a while with no callback received, and resolve or time it out.')]
class ReconcileStuckMpesaTransactions extends Command
{
    public function handle(PaymentGatewayService $gateway, MpesaCallbackController $callbackController): int
    {
        $stuck = MpesaTransaction::where('status', 'pending')
            ->whereNotNull('checkout_request_id')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->get();

        $resolved = 0;
        $timedOut = 0;
        $needsReview = 0;

        foreach ($stuck as $transaction) {
            $result = $gateway->queryStkStatus($transaction);

            if ($result !== null && array_key_exists('ResultCode', $result)) {
                $resultCode = (int) $result['ResultCode'];
                $resultDesc = $result['ResultDesc'] ?? null;

                if ($resultCode === 0) {
                    // Safaricom confirms this succeeded, but the status
                    // query carries no CallbackMetadata — no M-Pesa receipt
                    // number, no confirmed settled amount. Crediting the
                    // tenant from a guess would record a Payment with no
                    // real receipt behind it, which breaks both the A3
                    // duplicate-receipt guard and the audit trail. Flagged
                    // for the landlord to verify and enter manually instead.
                    $this->flagForManualVerification($transaction, $resultDesc);
                    $needsReview++;

                    continue;
                }

                // A definitive failure/cancellation needs no metadata —
                // the exact same path the real callback would have taken.
                $callbackController->processResult($transaction->checkout_request_id, $resultCode, $resultDesc);
                $resolved++;

                continue;
            }

            // Safaricom has no answer either (still processing, or the
            // query itself failed) — give up after a longer window, same
            // reasoning the client-side timeout already uses.
            if ($transaction->created_at->lt(now()->subMinutes(10))) {
                $transaction->update(['status' => 'timeout']);
                $timedOut++;
            }
        }

        $this->info("Reconciled {$resolved}, timed out {$timedOut}, flagged {$needsReview} for manual verification.");

        return self::SUCCESS;
    }

    private function flagForManualVerification(MpesaTransaction $transaction, ?string $resultDesc): void
    {
        $transaction->update([
            'result_code' => 0,
            'result_desc' => 'Safaricom confirms this payment succeeded, but no callback was ever received — needs manual verification against the tenant\'s M-Pesa statement, then recording via Record Payment.',
            'status' => 'failed',
        ]);

        Log::critical('M-Pesa reconciliation found a successful payment with no callback ever received.', [
            'transaction_id' => $transaction->id,
            'checkout_request_id' => $transaction->checkout_request_id,
            'result_desc' => $resultDesc,
        ]);

        $tenancy = $transaction->tenancy()->with('house.block.property', 'tenant')->first();

        if (! $tenancy) {
            return;
        }

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'mpesa_callback_missed',
            'An M-Pesa Payment May Need Manual Verification',
            "{$tenancy->tenant->name} ({$tenancy->house->house_number}) attempted an M-Pesa payment of KSh ".number_format((float) $transaction->amount, 2).
                ' that Safaricom confirms went through, but no callback was ever received by this system — please check your M-Pesa statement and record it manually if it did go through.',
            ['in_app', 'email'],
            $tenancy,
        );
    }
}
