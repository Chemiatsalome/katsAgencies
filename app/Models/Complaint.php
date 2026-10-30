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
    'tenant_id', 'house_id', 'property_id', 'tenancy_id', 'category', 'subject', 'description',
    'priority', 'status', 'assigned_to', 'resolution', 'resolved_at', 'submitted_by',
    'tenant_confirmed_at', 'related_complaint_id',
])]
class Complaint extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'tenant_confirmed_at' => 'datetime',
        ];
    }

    /** Resolved by the landlord, but the tenant hasn't yet said whether the work actually fixed it. */
    public function awaitingTenantConfirmation(): bool
    {
        return $this->status === 'resolved' && $this->tenant_confirmed_at === null;
    }

    /** Confirmed fixed and the conversation is closed — only confirmResolved() ever sets this. */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /** The earlier, closed request this one was filed as a recurrence of, if any. */
    public function relatedComplaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'related_complaint_id');
    }

    /** Later complaints filed as "this came back" against this one. */
    public function followUps(): HasMany
    {
        return $this->hasMany(Complaint::class, 'related_complaint_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * §8 (design approved 2026-10-25): nullable, captured only at creation
     * time (Tenant\Maintenance\Create::submit()) when the filing tenant's
     * specific active tenancy is already known — never guessed later from
     * tenant_id/house_id/created_at. NULL for existing (pre-§8) complaints
     * and for any complaint not filed through that one tenant-facing form.
     * An occurrence/association fact only — never on its own grounds for
     * tenant liability (see TenantLiabilityDecisionService, §7).
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ComplaintUpdate::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function maintenanceTask(): HasOne
    {
        return $this->hasOne(MaintenanceTask::class)->latestOfMany();
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['resolved', 'closed']);
    }

    public function scopeUrgent(Builder $query): Builder
    {
        return $query->where('priority', 'emergency');
    }

    /** A complaint can be filed against a house or directly against a property — check whichever is set. */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->whereHas('house', fn (Builder $hq) => $hq->accessibleBy($user))
                ->orWhereHas('property', fn (Builder $pq) => $pq->accessibleBy($user));
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'priority', 'assigned_to', 'resolution'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
