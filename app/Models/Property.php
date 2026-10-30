<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'landlord_id', 'name', 'type', 'address', 'latitude', 'longitude', 'photo_path', 'description', 'status',
    'purchase_price', 'initial_renovation_cost', 'other_acquisition_costs', 'current_estimated_value',
])]
class Property extends Model
{
    use LogsActivity;

    /** Centralized so Properties\Form and Properties\Wizard validate against the exact same list, instead of two hardcoded 'in:...' strings drifting apart. */
    public const TYPES = [
        'apartment', 'residential_compound', 'individual_house', 'maisonette',
        'commercial_building', 'mixed_use', 'villas', 'other',
    ];

    protected static function booted(): void
    {
        // documents() is polymorphic — there's no DB-level FK to cascade
        // this, and Document's own deleting hook (which removes the
        // physical file) only fires per-row through Eloquent, not via a
        // parent hard delete, so it has to be triggered explicitly here.
        // Houses cascade-delete at the DB layer (bypassing Eloquent events),
        // so their documents need cleaning up here too, not just Property's own.
        static::deleting(function (Property $property) {
            $property->documents->each->delete();
            $property->houses()->with('documents')->get()->each(fn (House $house) => $house->documents->each->delete());
        });
    }

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'initial_renovation_cost' => 'decimal:2',
            'other_acquisition_costs' => 'decimal:2',
            'current_estimated_value' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
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

    /**
     * Served straight from the public disk — unlike every other upload in
     * this app (ID scans, signed leases, payout evidence), a property photo
     * is meant to render in a public-ish card grid, not sit behind
     * DocumentPolicy/DocumentDownloadController. Null when none was set.
     */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function scopeForLandlord($query, int $landlordId)
    {
        return $query->where('landlord_id', $landlordId);
    }

    /**
     * Everything this user (landlord, manager, or admin) is allowed to see —
     * the single scope both list-view queries and Policy checks should run
     * through, so the two can never drift apart. Admin gets no restriction
     * (matches the existing Gate::before bypass); a landlord is restricted to
     * their own properties; a manager is further restricted to whichever of
     * the landlord's properties they were assigned (or none, if unassigned).
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        $landlordId = $user->landlordId();
        $query->where('landlord_id', $landlordId ?? 0);

        if ($propertyIds = $user->accessiblePropertyIds()) {
            $query->whereIn('id', $propertyIds);
        }

        return $query;
    }

    /** Total capital invested — purchase price + renovation + other acquisition costs. */
    public function totalInvestment(): float
    {
        return (float) $this->purchase_price + (float) $this->initial_renovation_cost + (float) $this->other_acquisition_costs;
    }

    /** Gross rental income over the trailing 12 months (rent collected, not merely expected). */
    public function annualGrossIncome(): float
    {
        return (float) Payment::genuine()->whereHas(
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
