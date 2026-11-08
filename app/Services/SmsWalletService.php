<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\MpesaTransaction;
use App\Models\Setting;
use App\Models\SmsWalletEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A landlord's prepaid balance for SMS rent reminders — opt-in, since every
 * SMS costs Kats real money via Africa's Talking. Balance is never a stored
 * column, only ever derived by summing sms_wallet_entries (credit rows from
 * a confirmed top-up, debit rows from an SMS actually sent), the same
 * convention DisbursementService::owed() uses for payout balances.
 */
class SmsWalletService
{
    private const MINIMUM_TOPUP = 20.0;

    public function rate(): float
    {
        return (float) Setting::get('sms_rate_per_message', '1.00');
    }

    public function balance(int $landlordId): float
    {
        $credits = SmsWalletEntry::where('landlord_id', $landlordId)->where('type', 'credit')->sum('amount');
        $debits = SmsWalletEntry::where('landlord_id', $landlordId)->where('type', 'debit')->sum('amount');

        return round((float) $credits - (float) $debits, 2);
    }

    /**
     * @return array{MerchantRequestID:string,CheckoutRequestID:string,ResponseCode:string,ResponseDescription:string,CustomerMessage:string}
     */
    public function initiateTopUp(int $landlordId, int $initiatedBy, string $phone, float $amount): array
    {
        if ($amount < self::MINIMUM_TOPUP) {
            throw new InvalidArgumentException('Minimum top-up is KSh '.number_format(self::MINIMUM_TOPUP, 2).'.');
        }

        // Always Kats' own platform shortcode — a landlord paying for SMS
        // credit is paying Kats, never themselves, regardless of how they
        // collect rent (see PaymentGatewayService::initiatePlatformStkPush()).
        $response = app(PaymentGatewayService::class)->initiatePlatformStkPush(
            $phone, $amount, 'SMSWALLET', 'SMS Credit'
        );

        MpesaTransaction::create([
            'tenancy_id' => null,
            'landlord_id' => $landlordId,
            'initiated_by' => $initiatedBy,
            'phone' => $phone,
            'amount' => $amount,
            'purpose' => 'sms_topup',
            'merchant_request_id' => $response['MerchantRequestID'] ?? null,
            'checkout_request_id' => $response['CheckoutRequestID'] ?? null,
            'status' => 'pending',
        ]);

        return $response;
    }

    /** Called from MpesaCallbackController once a 'sms_topup' transaction's STK push actually confirms. */
    public function confirmTopUp(MpesaTransaction $transaction, float $amount, ?string $receiptNumber, Carbon $transactionDate): void
    {
        $transaction->update([
            'mpesa_receipt_number' => $receiptNumber,
            'transaction_date' => $transactionDate,
        ]);

        SmsWalletEntry::create([
            'landlord_id' => $transaction->landlord_id,
            'type' => 'credit',
            'amount' => $amount,
            'mpesa_transaction_id' => $transaction->id,
            'description' => 'M-Pesa top-up'.($receiptNumber ? " ({$receiptNumber})" : ''),
        ]);

        app(NotificationService::class)->notify(
            User::findOrFail($transaction->landlord_id),
            'sms_wallet_topped_up',
            'SMS Wallet Topped Up',
            'Your SMS wallet was topped up with KSh '.number_format($amount, 2).'. New balance: KSh '.number_format($this->balance($transaction->landlord_id), 2).'.',
            ['in_app'],
        );
    }

    /** Called from MpesaCallbackController when a 'sms_topup' STK push fails or is cancelled. */
    public function notifyTopUpFailure(MpesaTransaction $transaction, bool $cancelled, ?string $resultDesc): void
    {
        app(NotificationService::class)->notify(
            User::findOrFail($transaction->landlord_id),
            'sms_wallet_topup_failed',
            $cancelled ? 'SMS Wallet Top-Up Cancelled' : 'SMS Wallet Top-Up Did Not Go Through',
            $cancelled
                ? 'Your SMS wallet top-up was cancelled — nothing was charged. You can try again any time.'
                : 'Your SMS wallet top-up attempt did not go through ('.($resultDesc ?: 'unknown reason').'). Nothing was charged — you can try again.',
            ['in_app'],
        );
    }

    /**
     * Debits the cost of one SMS, only ever called after it has actually
     * sent — never charges for an SMS that failed to deliver. Locks the
     * landlord's own User row for the duration so two reminders firing
     * near-simultaneously can't both read the same pre-write balance and
     * both pass the check below.
     */
    public function debit(int $landlordId, float $amount, ?AppNotification $notification = null, string $description = 'SMS reminder sent'): bool
    {
        return DB::transaction(function () use ($landlordId, $amount, $notification, $description) {
            User::where('id', $landlordId)->lockForUpdate()->first();

            if ($this->balance($landlordId) < $amount) {
                return false;
            }

            SmsWalletEntry::create([
                'landlord_id' => $landlordId,
                'type' => 'debit',
                'amount' => $amount,
                'app_notification_id' => $notification?->id,
                'description' => $description,
            ]);

            return true;
        });
    }

    /**
     * G-2: called only when an SMS has already been sent — irreversibly,
     * and already costing Kats real money — but debit() refused because a
     * concurrent debit exhausted the balance between NotificationService's
     * earlier pre-check and the debit() call. The cost is real regardless,
     * so it's recorded unconditionally here, which may take the landlord's
     * balance negative — the honest, auditable reflection of a message
     * they genuinely owe for, not a bug to paper over. Never call this for
     * any other reason; debit() itself remains the only normal path.
     *
     * Guarded by the same `(app_notification_id, type)` unique constraint
     * debit() relies on (see settleBilling()) — a second call for the same
     * notification raises a database integrity exception rather than
     * silently recording the discrepancy twice.
     */
    public function recordUnbilledSend(int $landlordId, float $amount, AppNotification $notification): void
    {
        SmsWalletEntry::create([
            'landlord_id' => $landlordId,
            'type' => 'debit',
            'amount' => $amount,
            'app_notification_id' => $notification->id,
            'description' => 'SMS sent but balance was already exhausted by a concurrent debit — billing discrepancy, reconcile manually.',
        ]);
    }

    /**
     * G-1A: the only path that ever bills a notification already confirmed
     * sent — called both right after a fresh successful send and, later,
     * to resume billing for one left at billing_status='pending' by an
     * earlier interruption (NotificationService::resume()). The wallet
     * debit (or, on failure, the forced discrepancy entry) and this
     * notification's billing_status both commit as one transaction: debit()
     * opens its own transaction internally, which — nested here under
     * MySQL, which supports savepoints — only actually commits when this
     * outer transaction does, so a crash between "entry written" and
     * "billing_status updated" cannot happen; either both land or neither
     * does. The unique constraint on sms_wallet_entries(app_notification_id,
     * type) is what actually enforces "at most one debit per notification"
     * at the database level — this method is what keeps that constraint
     * from ever being hit on the normal path (a second call here only
     * happens if billing_status was left at 'pending' by a genuine prior
     * interruption, never as a routine retry).
     */
    public function settleBilling(int $landlordId, float $amount, AppNotification $notification): void
    {
        DB::transaction(function () use ($landlordId, $amount, $notification) {
            // Row-locked re-check: two concurrent settleBilling() calls for
            // the same notification (e.g. a reconciliation resume() racing
            // the original call) must never both attempt to bill it. The
            // lock serializes them against each other; whichever arrives
            // second sees billing_status already resolved and does nothing
            // — the same kind of guard debit() already applies to the
            // landlord's own row, applied here at the notification level.
            $current = AppNotification::where('id', $notification->id)->lockForUpdate()->first();

            if (! $current || $current->billing_status !== 'pending') {
                return;
            }

            $billed = $this->debit($landlordId, $amount, $notification);

            if (! $billed) {
                $this->recordUnbilledSend($landlordId, $amount, $notification);
            }

            $current->update(['billing_status' => $billed ? 'charged' : 'reconciliation_required']);
        });
    }
}
