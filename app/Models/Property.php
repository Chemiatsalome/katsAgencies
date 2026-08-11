<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'landlord_id', 'name', 'type', 'address', 'description', 'status',
    'purchase_price', 'initial_renovation_cost', 'other_acquisition_costs', 'current_estimated_value',
])]
class Property extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'initial_renovation_cost' => 'decimal:2',
            'other_acquisition_costs' => 'decimal:2',
            'current_estimated_value' => 'decimal:2',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    public function houses(): HasManyThrough
    {
        return $this->hasManyThrough(House::class, Block::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'desired_property_id');
    }

    public function maintenanceTasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class);
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeForLandlord($query, int $landlordId)
    {
        return $query->where('landlord_id', $landlordId);
    }

    /** Total capital invested — purchase price + renovation + other acquisition costs. */
    public function totalInvestment(): float
    {
        return (float) $this->purchase_price + (float) $this->initial_renovation_cost + (float) $this->other_acquisition_costs;
    }

    /** Gross rental income over the trailing 12 months (rent collected, not merely expected). */
    public function annualGrossIncome(): float
    {
        return (float) Payment::whereHas(
            'tenancy.house.block',
            fn ($q) => $q->where('property_id', $this->id)
        )->where('payment_date', '>=', now()->subYear())->sum('amount');
    }

    /** Operating expenses over the trailing 12 months. */
    public function annualExpenses(): float
    {
        return (float) $this->expenses()->where('expense_date', '>=', now()->subYear())->sum('amount');
    }

    public function netOperatingIncome(): float
    {
        return $this->annualGrossIncome() - $this->annualExpenses();
    }

    /** Annual gross rent ÷ investment (or current value if set) × 100. */
    public function grossYield(): ?float
    {
        $base = (float) $this->current_estimated_value ?: $this->totalInvestment();

        return $base > 0 ? round($this->annualGrossIncome() / $base * 100, 1) : null;
    }

    /** Annual net operating income ÷ total investment × 100. */
    public function netYield(): ?float
    {
        $investment = $this->totalInvestment();

        return $investment > 0 ? round($this->netOperatingIncome() / $investment * 100, 1) : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['landlord_id', 'name', 'type', 'address', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
