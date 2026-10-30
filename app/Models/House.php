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

    /** Centralized so Houses\Form and Properties\Wizard validate against the exact same list, instead of two hardcoded 'in:...' strings drifting apart. */
    public const TYPES = [
        'bedsitter', 'studio', 'sq', 'one_bedroom', 'two_bedroom', 'three_bedroom',
        'four_bedroom', 'house', 'maisonette', 'shop', 'office', 'villa', 'other',
    ];

    /**
     * Only the explicitly numbered types have a bedroom count implied by
     * the type itself — everything else (bedsitter, studio, sq, house,
     * maisonette, shop, office, villa, other) genuinely varies per unit, so
     * those stay landlord-entered rather than guessed.
     */
    public const BEDROOMS_BY_TYPE = [
        'one_bedroom' => 1,
        'two_bedroom' => 2,
        'three_bedroom' => 3,
        'four_bedroom' => 4,
    ];

    public static function bedroomsImpliedByType(?string $type): ?int
    {
        return self::BEDROOMS_BY_TYPE[$type] ?? null;
    }

    protected static function booted(): void
    {
        // documents() is polymorphic — there's no DB-level FK to cascade
        // this, and Document's own deleting hook (which removes the
        // physical file) only fires per-row through Eloquent, not via a
        // parent hard delete, so it has to be triggered explicitly here.
        static::deleting(fn (House $house) => $house->documents->each->delete());
    }

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

    /** Mirrors Property::scopeAccessibleBy, two relation hops deeper (block->property). */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('block', fn (Builder $q) => $q->accessibleBy($user));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['house_number', 'type', 'monthly_rent', 'deposit_amount', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
