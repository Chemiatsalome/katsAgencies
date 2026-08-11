<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'house_id', 'invited_by', 'existing_tenant_id', 'token', 'tenant_phone', 'tenant_email',
    'monthly_rent', 'deposit_required', 'rent_due_day', 'move_in_date', 'lease_end_date',
    'notice_period_days', 'status', 'expires_at', 'opened_at', 'completed_at', 'cancelled_at',
    'resulting_tenancy_id',
])]
class TenantInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'monthly_rent' => 'decimal:2',
            'deposit_required' => 'decimal:2',
            'rent_due_day' => 'integer',
            'move_in_date' => 'date',
            'lease_end_date' => 'date',
            'notice_period_days' => 'integer',
            'expires_at' => 'datetime',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function existingTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'existing_tenant_id');
    }

    public function resultingTenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class, 'resulting_tenancy_id');
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->expires_at->isPast();
    }

    /** Still open for a tenant to complete registration through. */
    public function isUsable(): bool
    {
        return in_array($this->status, ['pending', 'opened'], true) && ! $this->expires_at->isPast();
    }

    public function markOpened(): void
    {
        if ($this->status === 'pending') {
            $this->update(['status' => 'opened', 'opened_at' => now()]);
        }
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'opened'])->where('expires_at', '>', now());
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }
}
