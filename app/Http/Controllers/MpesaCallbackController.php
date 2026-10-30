<?php

namespace App\Http\Controllers;

use App\Models\DepositPayment;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Services\DepositService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public webhook Safaricom calls once an STK push has been resolved (paid,
 * cancelled, or failed). Must always respond 200 with the ack shape below —
 * that only tells Safaricom "callback received", not that our business logic
 * succeeded, so failures here are logged rather than surfaced to the caller.
 */
class MpesaCallbackController extends Controller
{
    public function handle(Request $request, string $token = ''): JsonResponse
    {
        // Daraja callbacks carry no signature — this secret path segment
        // (see PaymentGatewayService::callbackUrl()) is the only thing
        // standing between this endpoint and a forged "payment succeeded"
        // POST, so a mismatch here is refused outright, not just logged.
        // Checked against the same Admin > Settings > Payments-backed value
        // callbackUrl() embeds when initiating a push — reading config()
        // directly here would check the static .env value instead, which a
        // live-saved secret never actually updates.
        if (! hash_equals((string) app(PaymentGatewayService::class)->callbackSecret(), $token)) {
            Log::warning('M-Pesa callback rejected — missing or incorrect callback token.');
            abort(403);
        }

        $payload = $request->input('Body.stkCallback', []);
        $checkoutRequestId = $payload['CheckoutRequestID'] ?? null;

        if (! $checkoutRequestId) {
            Log::warning('M-Pesa callback missing CheckoutRequestID', ['payload' => $payload]);

            return $this->ack();
        }

        $resultCode = (int) ($payload['ResultCode'] ?? -1);
        $resultDesc = $payload['ResultDesc'] ?? null;

        $this->processResult($checkoutRequestId, $resultCode, $resultDesc, $payload);

        return $this->ack();
    }

    /**
     * The one place a definitive M-Pesa result actually gets applied — the
     * real callback above calls it with what Safaricom posted; the
     * scheduled reconciliation command (ReconcileStuckMpesaTransactions)
     * calls it with what a direct STK status query returned instead, for a
     * transaction whose callback never arrived at all. Keeping this as one
     * shared method is what makes the idempotency/locking guarantees below
     * apply no matter which caller supplies the result.
     */
    public function processResult(string $checkoutRequestId, int $resultCode, ?string $resultDesc, array $payload = []): void
    {
        DB::transaction(function () use ($checkoutRequestId, $resultCode, $resultDesc, $payload) {
            // Locked for the rest of this transaction so a retried or
            // concurrently-delivered duplicate callback can't both read the
            // same "still pending" row and both credit the tenant.
            $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                Log::warning('M-Pesa result for unknown CheckoutRequestID', ['checkout_request_id' => $checkoutRequestId]);

                return;
            }

            // Safaricom retries a callback it didn't get a 200 for, a
            // network hiccup can deliver the same one twice, and the
            // reconciliation command could query a transaction whose real
            // callback lands in the same instant. Once this transaction
            // already has a final result, any further result for it is a
            // no-op, never a second payment.
            if ($transaction->isFinished()) {
                Log::info('M-Pesa result for an already-finished transaction ignored (duplicate, retry, or race with reconciliation).', [
                    'transaction_id' => $transaction->id,
                    'status' => $transaction->status,
                ]);

                return;
            }

            $transaction->update([
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'raw_callback' => $payload ?: $transaction->raw_callback,
                'status' => match (true) {
                    $resultCode === 0 => 'success',
                    $resultCode === 1032 => 'cancelled',
                    default => 'failed',
                },
            ]);

            if ($resultCode === 0) {
                $this->recordSuccessfulPayment($transaction, $payload);
            } else {
                $this->notifyFailure($transaction, $resultCode, $resultDesc);
            }
        });
    }

    /**
     * Until now, a failed/cancelled STK push notified nobody — the tenant
     * only found out if their own tab was still open polling for the
     * result (see PayRent/PayDeposit::checkStatus()). Closing the app, or
     * the tab simply timing out, meant total silence even though nothing
     * was ever charged.
     */
    private function notifyFailure(MpesaTransaction $transaction, int $resultCode, ?string $resultDesc): void
    {
        $tenancy = $transaction->tenancy()->with('tenant')->first();

        if (! $tenancy || ! $tenancy->tenant) {
            return;
        }

        $cancelled = $resultCode === 1032;
        $isDeposit = $transaction->purpose === 'deposit';

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            $isDeposit ? 'deposit_payment_failed' : 'rent_payment_failed',
            $cancelled ? 'M-Pesa Payment Cancelled' : 'M-Pesa Payment Did Not Go Through',
            $cancelled
                ? 'Your M-Pesa payment was cancelled — nothing was charged. You can try again any time.'
                : 'Your M-Pesa payment attempt did not go through ('.($resultDesc ?: 'unknown reason').'). Nothing was charged — you can try again.',
            ['in_app'],
            $tenancy,
        );
    }

    private function recordSuccessfulPayment(MpesaTransaction $transaction, array $payload): void
    {
        $items = collect($payload['CallbackMetadata']['Item'] ?? []);
        $pluck = fn (string $name) => $items->firstWhere('Name', $name)['Value'] ?? null;

        $receiptNumber = $pluck('MpesaReceiptNumber');
        $callbackAmount = $pluck('Amount');
        $amount = (float) ($callbackAmount ?? $transaction->amount);

        // Safaricom only ever settles the exact amount this app requested in
        // the STK push — a "success" callback for a different amount does
        // not belong to the transaction it claims to (a forged or corrupted
        // payload), so it must never be allowed to credit the tenant.
        if ($callbackAmount !== null && (int) round($amount) !== (int) round((float) $transaction->amount)) {
            Log::critical('M-Pesa callback amount does not match the requested transaction amount — refusing to credit.', [
                'transaction_id' => $transaction->id,
                'expected_amount' => (float) $transaction->amount,
                'callback_amount' => $callbackAmount,
            ]);

            $transaction->update([
                'status' => 'failed',
                'result_desc' => 'Amount mismatch between STK request and callback — flagged, not credited.',
            ]);

            return;
        }

        $transactionDateRaw = $pluck('TransactionDate');

        $transactionDate = $transactionDateRaw
            ? Carbon::createFromFormat('YmdHis', (string) $transactionDateRaw)
            : now();

        // The same real receipt recorded twice — once here automatically,
        // once manually by a landlord/manager who didn't realize this
        // callback was still in flight (or vice versa). An M-Pesa receipt
        // number is unique to its one transaction, so if either ledger
        // already has it, crediting again would double-count real money.
        if ($receiptNumber && (
            Payment::where('reference_number', $receiptNumber)->where('method', 'mpesa')->exists()
            || DepositPayment::where('reference_number', $receiptNumber)->where('method', 'mpesa')->exists()
        )) {
            Log::warning('M-Pesa callback receipt already recorded elsewhere — refusing to credit again.', [
                'transaction_id' => $transaction->id,
                'mpesa_receipt_number' => $receiptNumber,
            ]);

            $transaction->update([
                'status' => 'failed',
                'result_desc' => 'Receipt already recorded (likely entered manually) — flagged, not credited again.',
            ]);

            return;
        }

        $tenancy = $transaction->tenancy()->with('tenant', 'house.block.property')->first();
        $notifications = app(NotificationService::class);

        if ($transaction->purpose === 'deposit') {
            $depositPayment = app(DepositService::class)->recordPayment(
                $tenancy,
                $amount,
                $transactionDate->toDateString(),
                'mpesa',
                $receiptNumber,
                'Paid via M-Pesa (tenant self-service)',
                $transaction->initiated_by,
            );

            $transaction->update([
                'mpesa_receipt_number' => $receiptNumber,
                'transaction_date' => $transactionDate,
                'deposit_payment_id' => $depositPayment->id,
            ]);

            $notifications->notify(
                $tenancy->tenant,
                'deposit_payment_confirmed',
                'Deposit Payment Received',
                'Your M-Pesa deposit payment of KSh '.number_format($amount, 2)." ({$receiptNumber}) has been received.",
                ['in_app'],
                $tenancy,
            );

            $notifications->notify(
                $tenancy->house->block->property->landlord,
                'tenant_paid_mpesa',
                'Tenant Paid Deposit via M-Pesa',
                "{$tenancy->tenant->name} ({$tenancy->house->house_number}) paid KSh ".number_format($amount, 2)." via M-Pesa towards their deposit ({$receiptNumber}).",
                ['in_app'],
                $tenancy,
            );

            return;
        }

        $payments = $this->allocatePayment($tenancy, $amount, $receiptNumber, $transactionDate, $transaction->initiated_by, $transaction->collection_mode);

        // mpesa_transactions.payment_id is a single FK and stays
        // legacy/first-only (existing code relies on it meaning "the
        // first payment") — allocate() can return several Payment rows
        // from this one callback, so mpesa_transaction_id on every one of
        // them is the complete, authoritative trace back to this callback.
        Payment::whereIn('id', $payments->pluck('id'))->update(['mpesa_transaction_id' => $transaction->id]);

        $transaction->update([
            'mpesa_receipt_number' => $receiptNumber,
            'transaction_date' => $transactionDate,
            'payment_id' => $payments->first()?->id,
        ]);

        $periodsCovered = $payments->count();
        $periodNote = $periodsCovered > 1 ? " (covering {$periodsCovered} rent periods)" : '';

        $notifications->notify(
            $tenancy->tenant,
            'payment_confirmed',
            'Payment Received',
            'Your M-Pesa payment of KSh '.number_format($amount, 2)." ({$receiptNumber}) has been received{$periodNote}.",
            ['in_app'],
            $tenancy,
        );

        $notifications->notify(
            $tenancy->house->block->property->landlord,
            'tenant_paid_mpesa',
            'Tenant Paid via M-Pesa',
            "{$tenancy->tenant->name} ({$tenancy->house->house_number}) paid KSh ".number_format($amount, 2)." via M-Pesa ({$receiptNumber}).",
            ['in_app'],
            $tenancy,
        );
    }

    /**
     * Apply a lump-sum M-Pesa payment across whatever is actually outstanding
     * on the tenancy right now, oldest period first — a single STK push can
     * cover several months' arrears in one go, so it isn't limited to one
     * rent charge. Anything left over after every outstanding charge is
     * covered is applied as an advance against the most recent charge.
     *
     * @return \Illuminate\Support\Collection<int, Payment>
     */
    private function allocatePayment(Tenancy $tenancy, float $amount, ?string $receiptNumber, Carbon $date, int $recordedBy, ?string $collectionMode)
    {
        return app(\App\Services\PaymentAllocationService::class)->allocate($tenancy, $amount, $date, [
            'method' => 'mpesa',
            'collection_mode' => $collectionMode,
            'reference_number' => $receiptNumber,
            'recorded_by' => $recordedBy,
            'notes' => 'Paid via M-Pesa (tenant self-service)',
            'source' => 'system_payment',
            // Safaricom already verified this — never a tenant claim
            // waiting on anyone's confirmation.
            'status' => 'confirmed',
        ]);
    }

    private function ack(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
