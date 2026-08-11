<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenancy_id', 'rent_charge_id', 'initiated_by', 'phone', 'amount',
    'merchant_request_id', 'checkout_request_id', 'status', 'result_code',
    'result_desc', 'mpesa_receipt_number', 'transaction_date', 'payment_id', 'raw_callback',
])]
class MpesaTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'datetime',
            'raw_callback' => 'array',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function rentCharge(): BelongsTo
    {
        return $this->belongsTo(RentCharge::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['success', 'failed', 'cancelled', 'timeout'], true);
    }
}
