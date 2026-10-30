<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'desired_property_id', 'desired_house_id', 'applicant_name', 'applicant_phone',
    'applicant_email', 'application_date', 'status', 'notes',
    'reviewed_by', 'reviewed_at', 'converted_tenancy_id',
])]
class Application extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'application_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'desired_property_id');
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class, 'desired_house_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function convertedTenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class, 'converted_tenancy_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['submitted', 'under_review', 'more_info_required']);
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('property', fn (Builder $q) => $q->accessibleBy($user));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'reviewed_by', 'converted_tenancy_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
