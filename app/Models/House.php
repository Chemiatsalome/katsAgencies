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
        'four_bedroom', 'house', 'maisonette', 'shop', 'stall', 'office', 'villa', 'other',
    ];

    /**
     * Mirrors Property::CATEGORY_TYPES for the Rentable Spaces step's own
     * unit_type dropdown — which rentable-space types make sense for the
     * landlord's chosen category. 'mixed_use' is deliberately absent: a
     * mixed-use development must be able to combine any of these types
     * within the same property (see Properties\Wizard's $groups), so that
     * category leaves this list unfiltered rather than picking one.
     */
    public const CATEGORY_UNIT_TYPES = [
        'residential' => [
            'bedsitter', 'studio', 'sq', 'one_bedroom', 'two_bedroom',
            'three_bedroom', 'four_bedroom', 'house', 'maisonette', 'villa',
        ],
        'commercial' => ['shop', 'stall'],
        'office' => ['office'],
    ];

    /** @return string[] TYPES values to offer for this category, 'other' always appended last. Unknown/blank/mixed_use category = every TYPES value (today's unfiltered behaviour). */
    public static function typesForCategory(string $category): array
    {
        $types = self::CATEGORY_UNIT_TYPES[$category] ?? null;

        return $types === null ? self::TYPES : [...$types, 'other'];
    }

    /**
     * The only TYPES values Bedrooms genuinely never applies to — kept as
     * an explicit exclusion list (rather than the inverse) so 'other' and
     * any future type default to still showing the field, exactly as every
     * type already behaved before categories existed.
     */
    public const NON_RESIDENTIAL_TYPES = ['shop', 'stall', 'office'];

    public static function isResidentialType(string $type): bool
    {
        return ! in_array($type, self::NON_RESIDENTIAL_TYPES, true);
    }

    /**
     * Properties\Wizard's Rentable Spaces/Review/Deposit steps describe
     * each unit *group* using this, rather than the overall category's own
     * noun — the two only ever coincide for Residential/Office (where
     * every offered type maps to the same word anyway). Commercial offers
     * both Shop and Stall, and Mixed-use offers everything, so a single
     * property-wide noun can't describe every group's own rentable-space
     * type correctly; this is keyed by the group's actual unit_type instead.
     */
    public static function groupNoun(string $type): string
    {
        return match ($type) {
            'shop' => 'Shop',
            'stall' => 'Stall',
            'office' => 'Office',
            default => 'Unit',
        };
    }

    /** Str::headline('sq') would read "Sq", not the initialism landlords actually expect. */
    public static function typeLabel(string $type): string
    {
        return $type === 'sq' ? 'SQ' : \Illuminate\Support\Str::headline($type);
    }

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

    /** Human-readable labels for water_arrangement/electricity_arrangement — Phase 6 audit, P6-M-01. Mirrors the enum's own 3 values; anything missing or unrecognised degrades to a neutral label rather than printing a raw enum string or breaking. */
    public const UTILITY_ARRANGEMENT_LABELS = [
        'shared' => 'Shared',
        'separate_meter' => 'Separately metered',
        'included' => 'Included in rent',
    ];

    public static function utilityArrangementLabel(?string $value): string
    {
        return self::UTILITY_ARRANGEMENT_LABELS[$value] ?? 'Not specified';
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
