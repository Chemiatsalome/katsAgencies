<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A landlord's own escalation to the platform — a payment/billing/technical/
 * account problem that only an admin can act on, not something the normal
 * tenant/property/tenancy workflows cover. Previously there was no such
 * path at all; a landlord's only recourse was the generic "contact support"
 * message EnsureAccountIsActive shows reactively.
 */
#[Fillable(['landlord_id', 'category', 'subject', 'message', 'status', 'resolved_by', 'resolved_at'])]
class SupportTicket extends Model
{
    use LogsActivity;

    public const CATEGORIES = ['payment', 'technical', 'billing', 'account', 'other'];

    public const STATUSES = ['open', 'in_progress', 'resolved'];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class)->orderBy('created_at');
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
