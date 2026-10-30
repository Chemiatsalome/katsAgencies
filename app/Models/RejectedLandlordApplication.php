<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'email', 'phone', 'national_id', 'business_name', 'property_location',
    'intended_unit_count', 'reason', 'applied_at', 'rejected_by', 'rejected_at',
])]
class RejectedLandlordApplication extends Model
{
    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
