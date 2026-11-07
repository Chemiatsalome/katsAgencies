<?php

namespace App\Services;

use App\Http\Controllers\MpesaCallbackController;
use App\Models\DepositPayment;
use App\Models\MpesaStatusQuery;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 4 audit, Finding 4: the one place a stuck M-Pesa transaction is
 * investigated or resolved outside of a real Safaricom callback. Every
 * public method here either (a) asks Safaricom directly via a status query
 * and applies only what that query can safely support, or (b) records an
 * administrator's evidence-based decision — never a synthetic callback
 * through MpesaCallbackController::processResult() standing in for either.
 *
 * `status` never gains a new value from this service. The 5 admin-visible
 * states the design requires map onto the existing columns like this:
 *
 *   Awaiting provider response       status=pending,  investigation_outcome=null, cancellation_requested_at=null
 *   Cancellation requested           status=pending,  cancellation_requested_at set
 *   Requires manual verification     status=pending,  investigation_outcome=OUTCOME_ESCALATED
 *   Successfully resolved            status=success
 *   Failed/cancelled after verification   status IN (failed, cancelled), investigated_at set
 *
 * Every write here holds `lockForUpdate()` on the same transaction row
 * MpesaCallbackController::processResult() locks, and re-checks isFinished()
 * inside that lock — consistent, idempotent protection no matter which path
 * reaches the row first.
 */
class MpesaInvestigationService
{
    /**
     * Final-review point 1: a status query is inherently less trustworthy
     * than a real Safaricom-initiated callback — it's a response to OUR
     * request, can be transport-mangled, can describe a query-level error
     * rather than a payment outcome, and only a small set of ResultCode
     * values are both (a) commonly and consistently documented across
     * Safaricom's Daraja STK materials and (b) genuinely conclusive about
     * the queried transaction's own fate — not a transient or request-level
     * condition that could still resolve differently. Anything outside this
     * list is treated as unrecognised and left inconclusive, never guessed
     * at as a failure. MpesaCallbackController::processResult() deliberately
     * keeps its own broader "any non-zero code is a decline" rule for real
     * callbacks — Safaricom proactively pushing us a result is a different
     * trust level than us defensively parsing our own query's response, and
     * Finding 4 does not touch that existing, already-approved behaviour.
     *
     * Per-code review (STK Query context specifically):
     *
     *   0    Success. Consistently documented everywhere, incl. Safaricom's
     *        own simulator guidance. Conclusive — but never auto-credited on
     *        its own regardless (see queryAndInterpret()'s docblock): a
     *        query carries no CallbackMetadata to credit from safely.
     *   1    Insufficient balance on the customer's account. Consistently
     *        documented as a definitive decline; this codebase's own
     *        pre-existing reconciliation test fixture already relies on
     *        this exact code/meaning. Conclusive — insufficient funds
     *        cannot later "become" a success for the same push.
     *   1032 Request cancelled by user. Already trusted today for real
     *        callbacks in the existing, previously-audited
     *        processResult() match (Findings 1-3, untouched). Conclusive —
     *        a user-cancelled push has nothing left to resolve.
     *   1037 DS timeout — the subscriber could not be reached in time.
     *        Documented as the STK session's own delivery timeout, which
     *        ends that session; it cannot later complete. Conclusive.
     *   1019 Transaction has expired. Documented as the STK session's own
     *        expiry, ending it the same way 1037 does. Conclusive.
     *
     *   Removed after this review, on exactly the reasoning you asked for
     *   ("if a code can represent a transient, inconclusive or
     *   request-level error, remove it"):
     *
     *   1001 Documented as "Unable to lock subscriber, a transaction is
     *        already in process for the current subscriber" — this is a
     *        concurrency/locking condition at Safaricom's end (another
     *        in-flight request for the same phone number), not a verified
     *        statement about whether THIS CheckoutRequestID's own push
     *        succeeded or failed. The original transaction could still be
     *        genuinely pending. Request-level, not conclusive — removed.
     *   9999 No single, specific, stable STK-query meaning I can document
     *        with confidence — sources disagree (seen attributed to both
     *        "request cancelled" and generic/catch-all internal errors),
     *        which itself signals it is not safe to treat as conclusive.
     *        Removed.
     *   2001 Also removed on the same low-confidence basis — associated in
     *        memory with "invalid initiator information", a B2C/Reversal
     *        API concept that does not clearly map onto an STK consumer
     *        push outcome. The mismatch suggests a conflated/misremembered
     *        code rather than a verified STK-specific meaning, so it is
     *        excluded rather than guessed at.
     *
     * None of the five kept codes were independently re-verified against
     * Safaricom's current live documentation in this review — they are
     * retained on consistent, convergent documentation across multiple
     * Daraja integration references (and, for 0/1032/1, this codebase's own
     * prior reliance on them). The cost of this list being imperfect is
     * asymmetric by design: a wrongly-excluded conclusive code just means an
     * admin manually resolves something that could have auto-resolved
     * (inconvenient); a wrongly-included one would misreport a genuinely
     * unresolved payment as decided (unsafe). Every code here was chosen to
     * fail toward the safe side of that asymmetry.
     */
    private const KNOWN_RESULT_CODES = [0, 1, 1019, 1032, 1037];

    public function __construct(
        private PaymentGatewayService $gateway,
        private MpesaCallbackController $callbackController,
        private NotificationService $notifications,
    ) {
    }

    /**
     * Asks Safaricom directly what happened to this transaction, records
     * the attempt (regardless of outcome — this is the query *history*,
     * every attempt matters, not just the most recent), and applies the
     * result only where that's safe to do automatically:
     *
     *   - ResultCode 0 for an sms_topup: credited directly — that purpose
     *     carries no receipt-uniqueness guard to protect (see
     *     ReconcileStuckMpesaTransactions's prior reasoning, preserved here).
     *   - ResultCode 0 for rent/deposit: NEVER auto-credited — a status
     *     query carries no CallbackMetadata (no receipt, no confirmed
     *     amount), so recordSuccessfulPayment() cannot safely run. Escalated
     *     for manual verification instead.
     *   - Any other *recognised* ResultCode (a genuine decline or
     *     cancellation): needs no metadata to apply safely, so it is routed
     *     through the exact same processResult() a real callback would use.
     *   - Everything else — no answer, a non-"0" query-level ResponseCode,
     *     a CheckoutRequestID that doesn't match this transaction, a missing
     *     ResultCode, or an unrecognised ResultCode value — is inconclusive.
     *     Nothing is applied; the transaction stays exactly as unresolved as
     *     it was.
     */
    public function queryAndInterpret(MpesaTransaction $transaction, string $source, ?User $triggeredBy = null): MpesaStatusQuery
    {
        $rawResponse = $this->gateway->queryStkStatus($transaction);
        [$definitiveCode, $storedResultCode, $resultDesc] = $this->interpretQueryResponse($transaction, $rawResponse);

        $interpretedAs = 'inconclusive';

        $query = DB::transaction(function () use ($transaction, $source, $triggeredBy, $rawResponse, $definitiveCode, $storedResultCode, $resultDesc, &$interpretedAs) {
            $locked = MpesaTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($locked && ! $locked->isFinished()) {
                $locked->update(['last_queried_at' => now()]);

                if ($definitiveCode !== null) {
                    $interpretedAs = match (true) {
                        $definitiveCode === 0 && $locked->purpose === 'sms_topup' => 'sms_topup_credited',
                        $definitiveCode === 0 => 'escalated_manual_verification',
                        default => 'provider_result_applied',
                    };
                }
            }

            return MpesaStatusQuery::create([
                'mpesa_transaction_id' => $transaction->id,
                'source' => $source,
                'triggered_by' => $triggeredBy?->id,
                'result_code' => $storedResultCode,
                'result_desc' => $resultDesc,
                'raw_response' => $rawResponse !== null ? $this->sanitizeProviderResponse($rawResponse) : null,
                'interpreted_as' => $interpretedAs,
            ]);
        });

        // Deliberately outside the lock above: processResult() takes its
        // own lock on the same row, and escalateForManualVerification()
        // dispatches its own notifications after its own transaction — none
        // of this should run while this method's own lock is still held.
        match ($interpretedAs) {
            'sms_topup_credited', 'provider_result_applied' => $this->callbackController->processResult(
                $transaction->checkout_request_id, (int) $definitiveCode, $resultDesc, $rawResponse ?? []
            ),
            'escalated_manual_verification' => $this->escalateForManualVerification(
                $transaction,
                null,
                "Safaricom's status query confirms this payment succeeded, but the query response carries no receipt or confirmed amount — needs manual verification against the tenant's M-Pesa statement, then recording via Record Payment.",
            ),
            default => null,
        };

        return $query;
    }

    /**
     * Final-review point 1: validates the query response's own envelope
     * before trusting anything inside it as a payment outcome.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string} [definitive ResultCode or null, raw ResultCode as stored (for audit, even when not definitive), ResultDesc/errorMessage]
     */
    private function interpretQueryResponse(MpesaTransaction $transaction, ?array $rawResponse): array
    {
        if ($rawResponse === null) {
            return [null, null, null];
        }

        $rawResultCode = array_key_exists('ResultCode', $rawResponse) ? (string) $rawResponse['ResultCode'] : null;
        $resultDesc = $rawResponse['ResultDesc'] ?? ($rawResponse['errorMessage'] ?? null);

        // Safaricom's own "still processing" response for an unresolved
        // push carries no ResponseCode/ResultCode at all — just
        // errorCode/errorMessage. A non-"0" ResponseCode means the query
        // request itself wasn't cleanly processed, so whatever ResultCode
        // happens to be sitting in the payload cannot be trusted as the
        // actual payment outcome, even if present.
        if ((string) ($rawResponse['ResponseCode'] ?? '') !== '0') {
            return [null, $rawResultCode, $resultDesc];
        }

        // The response must unambiguously be about the exact transaction
        // this asked for — never apply a result to a transaction the
        // response didn't actually confirm it's describing.
        if (($rawResponse['CheckoutRequestID'] ?? null) !== $transaction->checkout_request_id) {
            return [null, $rawResultCode, $resultDesc];
        }

        if ($rawResultCode === null || ! is_numeric($rawResultCode)) {
            return [null, $rawResultCode, $resultDesc];
        }

        $resultCode = (int) $rawResultCode;

        if (! in_array($resultCode, self::KNOWN_RESULT_CODES, true)) {
            return [null, $rawResultCode, $resultDesc];
        }

        return [$resultCode, $rawResultCode, $resultDesc];
    }

    /**
     * A tenant asking us to look into a transaction that's been stuck too
     * long — not a real Safaricom cancellation (no such API exists), just a
     * request that triggers an immediate query and tells the admin team to
     * look. Only reachable once the transaction has been unresolved for a
     * while and hasn't already been requested.
     */
    public function requestCancellation(MpesaTransaction $transaction, User $tenant): MpesaTransaction
    {
        $locked = DB::transaction(function () use ($transaction, $tenant) {
            $locked = MpesaTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new InvalidArgumentException('Transaction not found.');
            }

            if ($locked->isFinished()) {
                throw new InvalidArgumentException('This transaction has already been resolved.');
            }

            if ($locked->cancellationRequested()) {
                throw new InvalidArgumentException('A cancellation request has already been submitted for this transaction.');
            }

            $locked->update([
                'cancellation_requested_at' => now(),
                'cancellation_requested_by' => $tenant->id,
            ]);

            return $locked;
        });

        $tenancy = $locked->tenancy()->with('house.block.property.landlord')->first();

        if ($tenancy?->house?->block?->property?->landlord) {
            $this->notifications->notify(
                $tenancy->house->block->property->landlord,
                'mpesa_cancellation_requested',
                'A Tenant Requested Help With a Stuck M-Pesa Payment',
                "{$tenant->name} ({$tenancy->house->house_number}) asked us to look into an M-Pesa payment of KSh ".number_format((float) $locked->amount, 2).' that never completed.',
                ['in_app'],
                $locked,
            );
        }

        $this->queryAndInterpret($locked, 'tenant_cancellation', $tenant);

        return $locked->fresh();
    }

    /**
     * Stays unresolved by design (clarification 2): status is never touched
     * here. Only investigation metadata is recorded, so the transaction
     * keeps blocking another payment attempt and remains eligible for a
     * genuine late callback or a later admin resolution.
     */
    public function escalateForManualVerification(MpesaTransaction $transaction, ?User $admin, string $notes): MpesaTransaction
    {
        if ($admin) {
            $this->assertAdmin($admin);
        }

        $justEscalated = false;

        $locked = DB::transaction(function () use ($transaction, $admin, $notes, &$justEscalated) {
            $locked = MpesaTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked || $locked->isFinished()) {
                return $locked;
            }

            // Avoid duplicate notifications for the same already-escalated
            // transaction — the 5-minute reconciliation cron (or a tenant's
            // own cancellation request) can easily reach this method again
            // for a transaction that's already sitting escalated.
            if ($locked->needsManualVerification()) {
                return $locked;
            }

            $locked->update([
                'investigation_outcome' => MpesaTransaction::OUTCOME_ESCALATED,
                'investigated_at' => now(),
                'investigated_by' => $admin?->id,
                'investigation_notes' => $notes,
            ]);

            $justEscalated = true;

            return $locked;
        });

        if (! $justEscalated) {
            return $locked;
        }

        $this->notifyEscalation($locked, $notes);

        return $locked->fresh();
    }

    /**
     * An admin's evidence-based decision that this transaction genuinely
     * failed or was cancelled, reached through investigation rather than a
     * provider result — e.g. the tenant confirms by phone that they cancelled
     * the PIN prompt, and Safaricom's own query keeps coming back
     * inconclusive. Distinct from a real callback/query reaching the same
     * status: `investigated_at` being set is what marks this one as
     * admin-verified rather than provider-sourced.
     */
    public function resolveAsFailed(MpesaTransaction $transaction, User $admin, string $reason): MpesaTransaction
    {
        return $this->resolveToTerminalStatus($transaction, $admin, 'failed', MpesaTransaction::OUTCOME_RESOLVED_FAILED, $reason);
    }

    public function resolveAsCancelled(MpesaTransaction $transaction, User $admin, string $reason): MpesaTransaction
    {
        return $this->resolveToTerminalStatus($transaction, $admin, 'cancelled', MpesaTransaction::OUTCOME_RESOLVED_CANCELLED, $reason);
    }

    private function resolveToTerminalStatus(MpesaTransaction $transaction, User $admin, string $status, string $outcome, string $reason): MpesaTransaction
    {
        $this->assertAdmin($admin);

        $locked = DB::transaction(function () use ($transaction, $admin, $status, $outcome, $reason) {
            $locked = MpesaTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new InvalidArgumentException('Transaction not found.');
            }

            if ($locked->isFinished()) {
                throw new InvalidArgumentException('This transaction has already been resolved — refresh and check its current status.');
            }

            $locked->update([
                'status' => $status,
                'investigated_at' => now(),
                'investigated_by' => $admin->id,
                'investigation_outcome' => $outcome,
                'investigation_notes' => $reason,
                'result_desc' => $reason,
            ]);

            return $locked;
        });

        $this->notifyResolution($locked, $status === 'cancelled'
            ? 'Your M-Pesa payment was cancelled after investigation — nothing was charged. You can try again any time.'
            : "Your M-Pesa payment attempt did not go through after investigation ({$reason}). Nothing was charged — you can try again.");

        return $locked->fresh();
    }

    /**
     * Links this transaction to a Payment/DepositPayment that was already
     * created independently (e.g. the landlord found the receipt on their
     * own M-Pesa statement and recorded it via Record Payment) — this method
     * never fabricates a credit itself. It only closes the loop between a
     * stuck transaction and a payment that genuinely, separately exists.
     */
    public function resolveAsSuccessful(MpesaTransaction $transaction, User $admin, string $referenceNumber, string $reason): MpesaTransaction
    {
        $this->assertAdmin($admin);

        $locked = DB::transaction(function () use ($transaction, $admin, $referenceNumber, $reason) {
            $locked = MpesaTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new InvalidArgumentException('Transaction not found.');
            }

            if ($locked->isFinished()) {
                throw new InvalidArgumentException('This transaction has already been resolved — refresh and check its current status.');
            }

            if ($locked->purpose === 'sms_topup') {
                throw new InvalidArgumentException('SMS wallet top-ups are not resolved through this action.');
            }

            // Final review, point 2: scoped to this exact tenancy and
            // purpose-appropriate ledger (never cross rent/deposit), and
            // confirmed — never a tenant's own unconfirmed claim standing in
            // for independent verification.
            $linked = $locked->purpose === 'deposit'
                ? DepositPayment::where('tenancy_id', $locked->tenancy_id)->where('reference_number', $referenceNumber)->confirmed()->first()
                : Payment::where('tenancy_id', $locked->tenancy_id)->where('reference_number', $referenceNumber)->confirmed()->first();

            if (! $linked) {
                throw new InvalidArgumentException('No confirmed payment with that reference was found for this tenancy — record it via Record Payment first, then resolve this transaction.');
            }

            // An unrelated existing ledger record must never be accepted
            // just because its reference string happens to match: the
            // amount must agree with what this transaction actually
            // attempted, and the record must not already be vouching for a
            // different stuck transaction (one real payment can resolve
            // exactly one stuck STK transaction, never several).
            if ((int) round((float) $linked->amount) !== (int) round((float) $locked->amount)) {
                throw new InvalidArgumentException('That payment\'s amount (KSh '.number_format((float) $linked->amount, 2).') does not match this transaction\'s amount (KSh '.number_format((float) $locked->amount, 2).') — this looks like an unrelated record, not this attempt.');
            }

            $alreadyLinkedElsewhere = $locked->purpose === 'deposit'
                ? MpesaTransaction::where('deposit_payment_id', $linked->id)->where('id', '!=', $locked->id)->exists()
                : ($linked->mpesa_transaction_id !== null && $linked->mpesa_transaction_id !== $locked->id);

            if ($alreadyLinkedElsewhere) {
                throw new InvalidArgumentException('That payment is already linked to a different M-Pesa transaction — it cannot vouch for this one too.');
            }

            $locked->update([
                'status' => 'success',
                'mpesa_receipt_number' => $referenceNumber,
                'payment_id' => $locked->purpose === 'deposit' ? $locked->payment_id : $linked->id,
                'deposit_payment_id' => $locked->purpose === 'deposit' ? $linked->id : $locked->deposit_payment_id,
                'investigated_at' => now(),
                'investigated_by' => $admin->id,
                'investigation_outcome' => MpesaTransaction::OUTCOME_RESOLVED_SUCCESSFUL,
                'investigation_notes' => $reason,
                'investigation_reference' => $referenceNumber,
            ]);

            // Payment carries its own back-reference to the M-Pesa
            // transaction it settles (see MpesaCallbackController's
            // identical bookkeeping for a real callback, which uses this
            // same query-builder form — mpesa_transaction_id is deliberately
            // not in Payment's #[Fillable] list, so a plain ->update() on
            // the model instance would silently no-op it) — kept in sync
            // here too, both for a complete audit trail and so the
            // already-linked-elsewhere guard above also protects a Payment
            // that was itself resolved this way.
            if ($locked->purpose !== 'deposit') {
                Payment::where('id', $linked->id)->update(['mpesa_transaction_id' => $locked->id]);
            }

            return $locked;
        });

        $this->notifyResolution($locked, 'Your M-Pesa payment of KSh '.number_format((float) $locked->amount, 2)." ({$referenceNumber}) has been confirmed and recorded.");

        return $locked->fresh();
    }

    private function notifyEscalation(MpesaTransaction $transaction, string $notes): void
    {
        if ($transaction->purpose === 'sms_topup') {
            return;
        }

        $tenancy = $transaction->tenancy()->with('house.block.property.landlord', 'tenant')->first();

        if (! $tenancy) {
            return;
        }

        if ($tenancy->house?->block?->property?->landlord) {
            $this->notifications->notify(
                $tenancy->house->block->property->landlord,
                'mpesa_callback_missed',
                'An M-Pesa Payment Needs Manual Verification',
                "{$tenancy->tenant?->name} ({$tenancy->house->house_number})'s M-Pesa payment of KSh ".number_format((float) $transaction->amount, 2)." needs manual verification: {$notes}",
                ['in_app', 'email'],
                $tenancy,
            );
        }

        if ($tenancy->tenant) {
            $this->notifications->notify(
                $tenancy->tenant,
                'mpesa_payment_needs_verification',
                'Payment Needs Verification',
                'Your M-Pesa payment of KSh '.number_format((float) $transaction->amount, 2)." may have gone through, but we're still confirming it — please don't pay again until this is resolved.",
                ['in_app'],
                $tenancy,
            );
        }
    }

    private function notifyResolution(MpesaTransaction $transaction, string $tenantBody): void
    {
        if ($transaction->purpose === 'sms_topup') {
            return;
        }

        $tenancy = $transaction->tenancy()->with('tenant')->first();

        if ($tenancy?->tenant) {
            $eventType = $transaction->status === 'success'
                ? ($transaction->purpose === 'deposit' ? 'deposit_payment_confirmed' : 'payment_confirmed')
                : ($transaction->purpose === 'deposit' ? 'deposit_payment_failed' : 'rent_payment_failed');

            $this->notifications->notify(
                $tenancy->tenant,
                $eventType,
                $transaction->status === 'success' ? 'Payment Confirmed' : 'Payment Resolved',
                $tenantBody,
                ['in_app'],
                $tenancy,
            );
        }
    }

    /** Defence in depth beyond the admin-only route this is normally reached through — this service takes a User, not a request, so it checks the role itself rather than trusting a caller already did. */
    private function assertAdmin(User $admin): void
    {
        if (! $admin->hasRole('admin')) {
            throw new AuthorizationException('Only an administrator can take this action.');
        }
    }

    /** Never persists a credential Safaricom's response might (even if it never has in practice) echo back. */
    private function sanitizeProviderResponse(array $response): array
    {
        $sensitive = ['password', 'passkey', 'token', 'authorization', 'secret'];

        $strip = function (array $data) use (&$strip, $sensitive): array {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = $strip($value);

                    continue;
                }

                foreach ($sensitive as $needle) {
                    if (str_contains(strtolower((string) $key), $needle)) {
                        unset($data[$key]);

                        break;
                    }
                }
            }

            return $data;
        };

        return $strip($response);
    }
}
