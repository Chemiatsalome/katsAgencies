<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'property_id', 'block_id', 'house_id', 'tenancy_id', 'complaint_id', 'maintenance_schedule_id', 'vendor_id',
    'title', 'category', 'description', 'priority', 'status', 'scheduled_date', 'scheduled_time',
    'estimated_cost', 'actual_cost', 'expense_id', 'deposit_deduction_id', 'completed_at', 'created_by',
])]
class MaintenanceTask extends Model
{
    use LogsActivity;

    public const OPEN_STATUSES = ['submitted', 'reviewed', 'assigned', 'scheduled', 'in_progress'];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'scheduled_time' => 'datetime:H:i',
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /**
     * §8 (design approved 2026-10-25): nullable — copied forward from
     * Complaint::tenancy_id when this task is created from a complaint
     * (Maintenance\Index::convertToTask()). NULL when a landlord logs a
     * task directly (createTask()), which has no tenant involved at all —
     * never guessed from house_id/created_at either way. An occurrence/
     * association fact only, never on its own grounds for tenant
     * liability (see TenantLiabilityDecisionService, §7).
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'maintenance_schedule_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function depositDeduction(): BelongsTo
    {
        return $this->belongsTo(DepositDeduction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeScheduledOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('scheduled_date', $date);
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('property', fn (Builder $q) => $q->accessibleBy($user));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'priority', 'vendor_id', 'scheduled_date', 'actual_cost'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
