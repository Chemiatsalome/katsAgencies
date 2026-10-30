<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
