<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable log of every individual thing that ever happened to a
 * TenancyNotice — issued, acknowledged, each tenant response, each landlord
 * decision, withdrawn, superseded, closed. TenancyNotice itself only holds
 * the *current* round's summary (for cheap display/filtering); this table
 * is what actually satisfies "a notice must never disappear from history" —
 * rows here are never updated or deleted.
 */
#[Fillable(['tenancy_notice_id', 'event_type', 'tenant_id', 'caused_by', 'note', 'new_vacate_date'])]
class TenancyNoticeEvent extends Model
{
    public const EVENT_TYPES = [
        'issued', 'acknowledged', 'dispute', 'request_time', 'request_extension',
        'decision_approve_time', 'decision_approve_extension', 'decision_decline',
        'withdrawn', 'superseded', 'closed',
    ];

    protected function casts(): array
    {
        return [
            'new_vacate_date' => 'date',
        ];
    }

    public function tenancyNotice(): BelongsTo
    {
        return $this->belongsTo(TenancyNotice::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function causedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caused_by');
    }
}
