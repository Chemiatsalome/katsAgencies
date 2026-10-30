<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['property_id', 'name', 'description'])]
class Block extends Model
{
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function houses(): HasMany
    {
        return $this->hasMany(House::class);
    }

    /** Mirrors Property::scopeAccessibleBy at one relation hop deeper. */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('property', fn (Builder $q) => $q->accessibleBy($user));
    }
}
