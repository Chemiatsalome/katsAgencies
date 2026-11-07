<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenancy_id', 'landlord_id', 'rent_charge_id', 'initiated_by', 'phone', 'amount', 'purpose', 'collection_mode',
    'merchant_request_id', 'checkout_request_id', 'status', 'result_code',
    'result_desc', 'mpesa_receipt_number', 'transaction_date', 'payment_id', 'deposit_payment_id', 'raw_callback',
    'last_queried_at', 'cancellation_requested_at', 'cancellation_requested_by',
    'investigated_at', 'investigated_by', 'investigation_outcome', 'investigation_notes', 'investigation_reference',
    'landlord_response', 'landlord_response_at', 'landlord_response_by',
])]
class MpesaTransaction extends Model
{
    /** See MpesaInvestigationService's docblock for what each value means and how it maps onto the admin UI's 5 states. */
    public const OUTCOME_ESCALATED = 'escalated_manual_verification';

    public const OUTCOME_RESOLVED_FAILED = 'resolved_failed';

    public const OUTCOME_RESOLVED_CANCELLED = 'resolved_cancelled';

    public const OUTCOME_RESOLVED_SUCCESSFUL = 'resolved_successful';

    /** The landlord's own acknowledgement of whether the money arrived — never a status value, never read as a resolution on its own. See MpesaInvestigationService::recordLandlordReceivedPayment()/recordLandlordDidNotReceivePayment(). */
    public const LANDLORD_RESPONSE_RECEIVED = 'received';

    public const LANDLORD_RESPONSE_NOT_RECEIVED = 'not_received';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'datetime',
            'raw_callback' => 'array',
            'last_queried_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'investigated_at' => 'datetime',
            'landlord_response_at' => 'datetime',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** Only set for purpose 'sms_topup' — every other purpose derives the landlord via tenancy. */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function rentCharge(): BelongsTo
    {
        return $this->belongsTo(RentCharge::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function depositPayment(): BelongsTo
    {
        return $this->belongsTo(DepositPayment::class);
    }

    public function cancellationRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by');
    }

    public function investigatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigated_by');
    }

    public function landlordResponseBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_response_by');
    }

    public function statusQueries(): HasMany
    {
        return $this->hasMany(MpesaStatusQuery::class)->latest();
    }

    /**
     * 'timeout' dropped deliberately (Phase 4 audit, Finding 4, clarification
     * 1): a transaction that got no callback within the client-side 3-minute
     * window is not resolved — Safaricom may still deliver a real callback,
     * or report a definitive result on the next reconciliation query, well
     * after that. Only a real provider result (success/failed/cancelled)
     * ends a transaction's life. Pre-existing 'timeout' rows written before
     * this fix are left as historical records, not rewritten — see
     * scopeUnresolved() below, which treats them as unresolved too.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, ['success', 'failed', 'cancelled'], true);
    }

    /** Still blocks another payment attempt and is still eligible for a genuine late result — covers both 'pending' and legacy 'timeout' rows. */
    public function scopeUnresolved($query)
    {
        return $query->whereNotIn('status', ['success', 'failed', 'cancelled']);
    }

    public function needsManualVerification(): bool
    {
        return $this->investigation_outcome === self::OUTCOME_ESCALATED;
    }

    public function cancellationRequested(): bool
    {
        return $this->cancellation_requested_at !== null;
    }

    public function landlordResponded(): bool
    {
        return $this->landlord_response !== null;
    }

    public function landlordConfirmedReceived(): bool
    {
        return $this->landlord_response === self::LANDLORD_RESPONSE_RECEIVED;
    }

    public function landlordDeniedReceived(): bool
    {
        return $this->landlord_response === self::LANDLORD_RESPONSE_NOT_RECEIVED;
    }

    /** The admin-visible states required by the Phase 4 Finding 4 design, extended with the landlord-response follow-up — see MpesaInvestigationService's docblock for the full mapping. */
    public function investigationState(): string
    {
        return match (true) {
            $this->status === 'success' => 'Successfully resolved',
            in_array($this->status, ['failed', 'cancelled'], true) => $this->investigated_at
                ? 'Resolved after verification'
                : ($this->status === 'cancelled' ? 'Cancelled' : 'Failed'),
            $this->landlordDeniedReceived() => 'Landlord says not received — admin investigation required',
            $this->landlordConfirmedReceived() => 'Landlord confirmed received — awaiting recording & resolution',
            $this->needsManualVerification() => 'Requires manual verification',
            $this->cancellationRequested() => 'Cancellation requested',
            default => 'Awaiting provider response',
        };
    }

    /**
     * Why a landlord is being asked to look at this at all — a tenant's own
     * cancellation request and an automated no-answer escalation are
     * different events and must read differently on the investigation page,
     * not be collapsed into one generic "something's wrong" message.
     */
    public function investigationReason(): string
    {
        if ($this->cancellationRequested()) {
            return 'Your tenant asked us to look into this payment — it has not completed.';
        }

        if ($this->needsManualVerification()) {
            return $this->investigation_notes
                ?: 'Our system could not get a definitive answer from Safaricom after repeated automatic attempts.';
        }

        return 'This payment has been flagged for investigation.';
    }
}
