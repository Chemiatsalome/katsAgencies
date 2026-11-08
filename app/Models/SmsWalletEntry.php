<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single source of truth for a landlord's prepaid SMS wallet — a
 * balance is never stored, only ever derived by summing this table (credit
 * rows from a confirmed top-up, debit rows from an SMS actually sent), the
 * same convention DisbursementService::owed() uses for payout balances.
 */
#[Fillable(['landlord_id', 'type', 'amount', 'mpesa_transaction_id', 'app_notification_id', 'description'])]
class SmsWalletEntry extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function mpesaTransaction(): BelongsTo
    {
        return $this->belongsTo(MpesaTransaction::class);
    }

    public function appNotification(): BelongsTo
    {
        return $this->belongsTo(AppNotification::class);
    }
}
