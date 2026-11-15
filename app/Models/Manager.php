<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'landlord_id', 'user_id', 'name', 'phone', 'email', 'all_properties', 'permissions',
    'status', 'token', 'expires_at', 'invited_at', 'activated_at',
])]
class Manager extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'all_properties' => 'boolean',
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'manager_property');
    }

    /**
     * Every active, activated manager genuinely eligible to receive a
     * notification about $property — assigned to it directly, or holding
     * blanket all_properties access. The one place "which managers care
     * about this property" is decided, so NotificationService's stakeholder
     * fan-out and anything else that needs the same answer never reimplement
     * the all_properties/pivot distinction themselves.
     *
     * Deliberately excludes a manager still only 'invited' (no user_id yet
     * — nothing to notify) and one 'inactive' (access already revoked) —
     * mirrors EnsureAccountIsActive's own notion of who can currently act,
     * so a deactivated or not-yet-accepted manager never receives a fresh
     * notification about ongoing property activity.
     */
    public function scopeEligibleForProperty(Builder $query, Property $property): Builder
    {
        return $query->where('landlord_id', $property->landlord_id)
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->where(fn (Builder $q) => $q->where('all_properties', true)
                ->orWhereHas('properties', fn (Builder $q2) => $q2->whereKey($property->id)));
    }

    public function isExpired(): bool
    {
        return $this->status === 'invited' && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === 'invited' && ! $this->expires_at->isPast();
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public function getActivitylogOptions(): LogOptions
    {
        // 'permissions' was missing here — a landlord changing exactly what
        // a manager can do left no trail at all, the same gap the
        // manual pivot-sync log in Managers\Form::save() closes for
        // property access (sync() has no model attribute of its own to log).
        return LogOptions::defaults()
            ->logOnly(['status', 'all_properties', 'permissions'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
