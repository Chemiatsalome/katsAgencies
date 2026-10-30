<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['landlord_id', 'property_id', 'name', 'body', 'is_default', 'is_active'])]
class ContractTemplate extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Templates are landlord-owned configuration — a manager sees the
     * templates of the landlord they're delegated to. ContractTemplatePolicy
     * currently blocks every manager from reaching a template at all
     * (landlord-only, deliberately never delegable), so the
     * accessiblePropertyIds() filter below is presently unreachable in
     * practice — kept for defensive consistency with every other model's
     * scopeAccessibleBy(), so a future relaxation of that policy wouldn't
     * silently leak a different property's template to a scoped manager.
     * A portfolio-wide template (property_id null) always stays visible,
     * matching resolveDefaultFor()'s own fallback semantics.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        $query->where('landlord_id', $user->landlordId() ?? 0);

        $propertyIds = $user->accessiblePropertyIds();

        if ($propertyIds !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('property_id')->orWhereIn('property_id', $propertyIds));
        }

        return $query;
    }

    /**
     * The template a new contract for this property should use: that
     * property's own default if it has one, otherwise the landlord's
     * portfolio-wide fallback (property_id null) — never a different
     * property's own template, since those are deliberately scoped.
     */
    public static function resolveDefaultFor(int $landlordId, ?int $propertyId): ?self
    {
        return static::where('landlord_id', $landlordId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->where(fn (Builder $q) => $q->where('property_id', $propertyId)->orWhereNull('property_id'))
            ->orderByRaw('property_id IS NULL')
            ->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'property_id', 'is_default', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
