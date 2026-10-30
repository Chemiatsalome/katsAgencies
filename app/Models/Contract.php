<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'tenancy_id', 'contract_template_id', 'document_id', 'source', 'status', 'body_snapshot', 'version',
    'sent_at', 'viewed_at', 'signed_by_name', 'signed_at', 'signed_ip', 'declined_at', 'declined_reason',
    'superseded_at',
])]
class Contract extends Model
{
    use LogsActivity;

    public const STATUSES = ['draft', 'generated', 'sent', 'awaiting_signature', 'signed', 'declined', 'expired'];

    public const SOURCES = ['system_template', 'landlord_upload'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
            'superseded_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function isAwaitingSignature(): bool
    {
        return $this->status === 'awaiting_signature';
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /**
     * What a landlord actually needs to see first: whether this version is
     * still the live one. `status` alone keeps its real signing history
     * (e.g. still "signed") even after a newer version replaces it, so
     * display code should read this instead of `status` directly wherever
     * it would otherwise show a superseded version as if it were current.
     */
    public function effectiveStatus(): string
    {
        return $this->isSuperseded() ? 'superseded' : $this->status;
    }

    public function isSystemTemplate(): bool
    {
        return $this->source === 'system_template';
    }

    public function isLandlordUpload(): bool
    {
        return $this->source === 'landlord_upload';
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenancy', fn (Builder $q) => $q->accessibleBy($user));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'signed_by_name', 'signed_at', 'superseded_at', 'declined_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
