<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'block_id', 'house_number', 'type', 'bedrooms', 'monthly_rent',
    'deposit_amount', 'electricity_arrangement', 'water_arrangement',
    'status', 'availability_date', 'description',
])]
class House extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'monthly_rent' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'availability_date' => 'date',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    public function currentTenancy(): HasOne
    {
        return $this->hasOne(Tenancy::class)->whereIn('status', Tenancy::OCCUPYING_STATUSES)->latestOfMany();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'desired_house_id');
    }

    public function maintenanceTasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeVacant(Builder $query): Builder
    {
        return $query->where('status', 'vacant');
    }

    public function scopeOccupied(Builder $query): Builder
    {
        return $query->where('status', 'occupied');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['house_number', 'type', 'monthly_rent', 'deposit_amount', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
