<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['documentable_type', 'documentable_id', 'category', 'original_filename', 'path', 'mime_type', 'size', 'uploaded_by'])]
class Document extends Model
{
    use LogsActivity;

    // No logOnlyDirty() here, deliberately — the "created" and "deleted"
    // events (who uploaded/removed a document, and when) are the audit
    // trail that actually matters; an "updated" event on this model never
    // happens today, but if it ever does, logging it too is the safer
    // default for a record that exists specifically to be auditable.
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['category', 'original_filename', 'documentable_type', 'documentable_id']);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Set only for a lease_agreement document generated/uploaded through ContractService — the contract this file IS, not just something attached to it. */
    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class, 'document_id');
    }

    /**
     * Never a direct public-storage URL — every document category here can
     * carry PII (ID scans, signed leases, payout evidence), so it's served
     * through an authenticated, per-record authorized route instead of a
     * guessable-but-permanent bearer-token link anyone who obtains it could
     * use forever. See DocumentDownloadController.
     */
    public function url(): string
    {
        return route('documents.show', $this);
    }

    protected static function booted(): void
    {
        // Hard stop at the model layer, not just in the UI that happens to
        // show a friendlier message first — a lease agreement's Document
        // row IS the contract's file (Contract::document_id points back at
        // it), so deleting it here would silently orphan that reference and
        // destroy a signed tenant's audit trail. Every delete path (the
        // Documents list, the per-tenancy upload widget, tinker) goes
        // through this same guard.
        static::deleting(function (Document $document) {
            if ($document->contract()->exists()) {
                return false;
            }
        });

        static::deleted(function (Document $document) {
            Storage::disk('local')->delete($document->path);
        });
    }
}
