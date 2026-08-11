<?php

namespace App\Http\Controllers;

use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Public webhook Safaricom calls once an STK push has been resolved (paid,
 * cancelled, or failed). Must always respond 200 with the ack shape below —
 * that only tells Safaricom "callback received", not that our business logic
 * succeeded, so failures here are logged rather than surfaced to the caller.
 */
class MpesaCallbackController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->input('Body.stkCallback', []);
        $checkoutRequestId = $payload['CheckoutRequestID'] ?? null;

        $transaction = $checkoutRequestId
            ? MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first()
            : null;

        if (! $transaction) {
            Log::warning('M-Pesa callback for unknown CheckoutRequestID', ['payload' => $payload]);

            return $this->ack();
        }

        $resultCode = (int) ($payload['ResultCode'] ?? -1);
        $resultDesc = $payload['ResultDesc'] ?? null;

        $transaction->update([
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'raw_callback' => $payload,
            'status' => match (true) {
                $resultCode === 0 => 'success',
                $resultCode === 1032 => 'cancelled',
                default => 'failed',
            },
        ]);

        if ($resultCode === 0) {
            $this->recordSuccessfulPayment($transaction, $payload);
        }

        return $this->ack();
    }

    private function recordSuccessfulPayment(MpesaTransaction $transaction, array $payload): void
    {
        $items = collect($payload['CallbackMetadata']['Item'] ?? []);
        $pluck = fn (string $name) => $items->firstWhere('Name', $name)['Value'] ?? null;

        $receiptNumber = $pluck('MpesaReceiptNumber');
        $amount = (float) ($pluck('Amount') ?? $transaction->amount);
        $transactionDateRaw = $pluck('TransactionDate');

        $transactionDate = $transactionDateRaw
            ? Carbon::createFromFormat('YmdHis', (string) $transactionDateRaw)
            : now();

        $tenancy = $transaction->tenancy()->with('tenant', 'house.block.property')->first();

        $payments = $this->allocatePayment($tenancy, $amount, $receiptNumber, $transactionDate, $transaction->initiated_by);

        $transaction->update([
            'mpesa_receipt_number' => $receiptNumber,
            'transaction_date' => $transactionDate,
            'payment_id' => $payments->first()?->id,
        ]);

        $notifications = app(NotificationService::class);
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
    private function allocatePayment(Tenancy $tenancy, float $amount, ?string $receiptNumber, Carbon $date, int $recordedBy)
    {
        $remaining = $amount;
        $payments = collect();

        $outstanding = $tenancy->rentCharges()->outstanding()->orderBy('due_date')->get();

        foreach ($outstanding as $charge) {
            if ($remaining <= 0) {
                break;
            }

            $due = (float) $charge->amount_expected - (float) $charge->amount_paid;
            $portion = min($due, $remaining);

            $payments->push(Payment::create([
                'tenancy_id' => $tenancy->id,
                'rent_charge_id' => $charge->id,
                'amount' => $portion,
                'payment_date' => $date->toDateString(),
                'method' => 'mpesa',
                'reference_number' => $receiptNumber,
                'recorded_by' => $recordedBy,
                'notes' => 'Paid via M-Pesa (tenant self-service)',
                'source' => 'system_payment',
            ]));

            $remaining -= $portion;
        }

        if ($remaining > 0) {
            $targetChargeId = $outstanding->last()->id
                ?? $tenancy->rentCharges()->orderByDesc('period_year')->orderByDesc('period_month')->value('id');

            $payments->push(Payment::create([
                'tenancy_id' => $tenancy->id,
                'rent_charge_id' => $targetChargeId,
                'amount' => $remaining,
                'payment_date' => $date->toDateString(),
                'method' => 'mpesa',
                'reference_number' => $receiptNumber,
                'recorded_by' => $recordedBy,
                'notes' => 'Paid via M-Pesa (tenant self-service) — advance payment',
                'source' => 'system_payment',
            ]));
        }

        return $payments;
    }

    private function ack(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
