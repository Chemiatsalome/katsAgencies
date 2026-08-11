<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['tenancy_id', 'amount', 'deductions', 'deduction_reason', 'refund_date', 'recorded_by'])]
class DepositRefund extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'refund_date' => 'date',
            'amount' => 'decimal:2',
            'deductions' => 'decimal:2',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tenancy_id', 'amount', 'deductions', 'deduction_reason', 'refund_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
