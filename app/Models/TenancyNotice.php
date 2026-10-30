<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A single tenancy notice and its current state. Issuing a new notice while
 * one is still open supersedes the old one rather than replacing it, and a
 * notice is never deleted — withdrawn/superseded/resolved/closed are all
 * terminal states that keep the row (and its documents/events) on record.
 * Every individual thing that happens to a notice is also appended to
 * TenancyNoticeEvent — this row only holds the *current* round's summary.
 */
#[Fillable([
    'tenancy_id', 'contract_id', 'sequence_number', 'issued_by', 'reason_category', 'reason_details',
    'notice_date', 'notice_period_days', 'proposed_vacate_date', 'status', 'acknowledged_at',
    'tenant_response_type', 'tenant_response_note', 'requested_new_vacate_date', 'decision',
    'decision_note', 'decided_by', 'decided_at', 'final_vacate_date', 'new_contract_id',
    'withdrawn_at', 'withdrawn_by', 'withdrawn_reason', 'superseded_at', 'superseded_by_notice_id',
    'closed_at', 'notes',
])]
class TenancyNotice extends Model
{
    use LogsActivity;

    public const REASON_CATEGORIES = ['noise_complaints', 'property_damage', 'rent_arrears', 'breach_of_agreement', 'other'];

    public const STATUSES = ['issued', 'acknowledged', 'under_review', 'resolved', 'withdrawn', 'superseded', 'closed'];

    public const RESPONSE_TYPES = ['dispute', 'request_time', 'request_extension'];

    public const DECISIONS = ['approve_time', 'approve_extension', 'decline'];

    /** Still "the live notice" for its tenancy — resolved stays open until closed/withdrawn/superseded, since vacating hasn't happened yet. */
    public const OPEN_STATUSES = ['issued', 'acknowledged', 'under_review', 'resolved'];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'requested_new_vacate_date' => 'date',
            'proposed_vacate_date' => 'date',
            'final_vacate_date' => 'date',
            'acknowledged_at' => 'datetime',
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'superseded_at' => 'datetime',
            'closed_at' => 'datetime',
            'sequence_number' => 'integer',
            'notice_period_days' => 'integer',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function newContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'new_contract_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function supersededByNotice(): BelongsTo
    {
        return $this->belongsTo(TenancyNotice::class, 'superseded_by_notice_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TenancyNoticeEvent::class)->orderBy('created_at');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isAwaitingDecision(): bool
    {
        return $this->status === 'under_review';
    }

    public function isRepeatNotice(): bool
    {
        return $this->sequence_number >= 2;
    }

    /** From the 3rd notice on, deciding requires the landlord to explicitly acknowledge the repeat history first — never an automatic block. */
    public function requiresReviewBanner(): bool
    {
        return $this->sequence_number >= 3;
    }

    /**
     * Reconstructs the tenant-facing status vocabulary (Issued, Disputed,
     * Extension Requested, Extension Approved, ...) from the trimmed set of
     * real DB statuses plus tenant_response_type/decision — the status
     * machine itself only needs 7 values, but the UI still needs to show
     * the full picture.
     */
    public function displayStatus(): string
    {
        if ($this->status === 'under_review') {
            return match ($this->tenant_response_type) {
                'dispute' => 'Disputed',
                'request_time' => 'Extension Requested',
                'request_extension' => 'Extension Requested',
                default => 'Under Review',
            };
        }

        if ($this->status === 'resolved') {
            return match ($this->decision) {
                'approve_time', 'approve_extension' => 'Extension Approved',
                'decline' => 'Extension Declined',
                default => 'Resolved',
            };
        }

        return match ($this->status) {
            'issued' => 'Issued',
            'acknowledged' => 'Acknowledged',
            'withdrawn' => 'Withdrawn',
            'superseded' => 'Superseded',
            'closed' => 'Closed',
            default => ucfirst($this->status),
        };
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where('status', 'under_review');
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenancy', fn (Builder $q) => $q->accessibleBy($user));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'tenant_response_type', 'decision', 'withdrawn_at', 'superseded_at', 'closed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
