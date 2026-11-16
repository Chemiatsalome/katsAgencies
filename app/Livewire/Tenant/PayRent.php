<?php

namespace App\Livewire\Tenant;

use App\Models\Document;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Services\MpesaInvestigationService;
use App\Services\MpesaService;
use App\Services\NotificationService;
use App\Services\PaymentAllocationService;
use App\Services\PaymentGatewayService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.tenant')]
class PayRent extends Component
{
    use WithFileUploads;

    public string $phone = '';

    /** 'full' | 'month' | 'custom' */
    public string $paymentOption = 'full';

    public string $amount = '';

    // Only ever set server-side, by pay() below, against the tenancy it
    // resolves there and there alone — #[Locked] refuses any client-sent
    // update to this property outright. checkStatus()/render() additionally
    // never trust the value alone: both re-scope the lookup to the
    // authenticated tenant's own tenancy, so even if this property were
    // ever compromised, it could not read or mutate another tenant's
    // transaction. See audit Finding 1 (Phase 4).
    #[Locked]
    public ?int $pendingTransactionId = null;

    // "I already paid offline" — a separate claim, not an STK push; stays
    // out of the ledger until a landlord confirms it (see
    // RentCharge::recalculateStatus(), Payment::scopeConfirmed()).
    public bool $showOfflineForm = false;

    public string $offline_method = 'bank';

    public string $offline_amount = '';

    public string $offline_payment_date = '';

    public string $offline_reference = '';

    public $offline_evidence = null;

    public function mount(): void
    {
        $tenant = Auth::user()->tenant;
        $this->phone = $tenant?->phone ?? '';

        $tenancy = $tenant?->currentTenancy();

        $fullBalance = $tenancy ? $tenancy->balance() : 0.0;
        $monthAmount = $this->monthAmount($tenancy);

        // Default to whichever option actually reflects what's owed — if only
        // one month is outstanding, "full" and "month" are the same amount anyway.
        $this->paymentOption = $fullBalance > 0 ? 'full' : 'custom';
        $this->amount = $fullBalance > 0 ? number_format($fullBalance, 2, '.', '') : ($monthAmount > 0 ? number_format($monthAmount, 2, '.', '') : '');

        // Phase 4 audit, Finding 4: a page reload used to lose track of an
        // in-flight STK push entirely (pendingTransactionId only ever lived
        // in Livewire's own component state). Restoring it here means a
        // tenant who closed the tab mid-payment sees it again on return,
        // rather than being free to start a second, unrelated attempt.
        // Only restorable when exactly one unresolved transaction exists —
        // see unresolvedTransactions() in render() for the (should-be-rare)
        // multiple case.
        $unresolved = $tenancy ? $this->unresolvedTransactionsQuery($tenancy)->get() : collect();

        if ($unresolved->count() === 1) {
            $this->pendingTransactionId = $unresolved->first()->id;
        }
    }

    private function unresolvedTransactionsQuery(Tenancy $tenancy)
    {
        return MpesaTransaction::where('tenancy_id', $tenancy->id)->where('purpose', 'rent')->unresolved();
    }

    public function updatedPaymentOption(): void
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();

        $this->amount = match ($this->paymentOption) {
            'full' => number_format($tenancy?->balance() ?? 0, 2, '.', ''),
            'month' => number_format($this->monthAmount($tenancy), 2, '.', ''),
            default => $this->amount,
        };
    }

    private function monthAmount(?Tenancy $tenancy): float
    {
        if (! $tenancy) {
            return 0.0;
        }

        $charge = $tenancy->rentCharges()->outstanding()->orderBy('due_date')->first();

        return $charge ? (float) $charge->amount_expected - (float) $charge->amount_paid : 0.0;
    }

    public function pay(): void
    {
        $this->validate([
            'phone' => ['required', 'string', 'regex:/^(?:0|\+?254)?7\d{8}$|^(?:0|\+?254)?1\d{8}$/'],
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        abort_if(! $tenancy, 404);

        // Phase 4 audit, Finding 4: a hard block, not an override-able
        // warning — while any attempt for this tenancy+purpose is still
        // unresolved (pending, or a legacy 'timeout' row), nothing may start
        // a second one. Re-checked server-side even though the view also
        // hides the form, since the view is never enforcement on its own.
        if ($this->unresolvedTransactionsQuery($tenancy)->exists()) {
            $this->addError('phone', 'You already have a rent payment attempt that hasn\'t resolved yet. Please wait for it, or ask for help below, before trying again.');

            return;
        }

        // PaymentGatewayService::initiateStkPush() already refuses this
        // below, but checking here first avoids an unnecessary gateway
        // round-trip and keeps the message specific to this page rather
        // than a generic caught RuntimeException.
        if ($tenant->currentTenancyLandlordIsSuspended()) {
            $this->addError('phone', "Online payment is unavailable right now because your landlord's Kats Agencies account is suspended. Your tenancy and rent obligations are unaffected — please contact your landlord directly about how to pay while this platform restriction is in place.");

            return;
        }

        try {
            $landlordId = $tenancy->house->block->property->landlord_id;
            $gateway = app(PaymentGatewayService::class);

            $collectionMode = $gateway->collectionModeFor($landlordId);

            // Mirrors the hidden button in the view — checked again here
            // since the view alone is never enforcement. A landlord on
            // manual tracking (or a direct setup already known broken) has
            // no M-Pesa destination to push to at all.
            if (! $collectionMode) {
                $this->addError('phone', 'Online payment is not available for this property right now. Please use "Already paid another way" below, or contact your landlord.');

                return;
            }

            // Which specific rent charge(s) this settles is decided at callback
            // time against whatever is actually outstanding then (oldest first) —
            // a lump sum can span several months, so it isn't pinned to one charge here.
            $transaction = MpesaTransaction::create([
                'tenancy_id' => $tenancy->id,
                'initiated_by' => Auth::id(),
                'phone' => app(MpesaService::class)->normalizePhone($this->phone),
                'amount' => $this->amount,
                'collection_mode' => $collectionMode,
                'status' => 'pending',
            ]);

            // Settles into the landlord's own shortcode when they've set up
            // direct collection, or Kats' own paybill otherwise — either way
            // decided by collectionModeFor() above, consistently.
            $response = $gateway->initiateStkPush(
                $landlordId,
                $this->phone,
                (float) $this->amount,
                'Rent-'.$tenancy->id,
                'Rent payment'
            );

            $transaction->update([
                'merchant_request_id' => $response['MerchantRequestID'] ?? null,
                'checkout_request_id' => $response['CheckoutRequestID'] ?? null,
            ]);

            $this->pendingTransactionId = $transaction->id;
        } catch (RuntimeException $e) {
            $this->addError('phone', $e->getMessage());
        }
    }

    /**
     * The one place every lookup of $pendingTransactionId goes through —
     * #[Locked] already stops the client from ever setting this property to
     * someone else's transaction id, but this re-verifies ownership
     * independently of that (never trust a property as proof of ownership
     * on its own). Scoped to this tenant's own tenancy, exactly like
     * Statements::downloadReceipt() scopes by tenancy_id — a mismatched or
     * missing id is indistinguishable from "no transaction" to the caller.
     */
    private function ownPendingTransaction(): ?MpesaTransaction
    {
        if (! $this->pendingTransactionId) {
            return null;
        }

        $tenancy = Auth::user()->tenant?->currentTenancy();

        if (! $tenancy) {
            return null;
        }

        return MpesaTransaction::where('tenancy_id', $tenancy->id)->find($this->pendingTransactionId);
    }

    /**
     * Phase 4 audit, Finding 4: no longer writes a terminal 'timeout' —
     * giving up locally used to make MpesaCallbackController::processResult()
     * treat the transaction as already-finished and silently discard a
     * genuinely late callback. This is now a pure read: it just re-scopes
     * the lookup (Finding 1's protection) and clears a dangling/foreign id;
     * whatever happens to the transaction itself is decided server-side by
     * a real callback, a reconciliation query, or an admin's resolution.
     */
    public function checkStatus(): void
    {
        if (! $this->pendingTransactionId) {
            return;
        }

        $transaction = $this->ownPendingTransaction();

        if (! $transaction) {
            // Either genuinely gone, or the id in hand doesn't belong to
            // this tenant's tenancy — both are treated identically: clear
            // it and never touch whatever that id actually points to.
            $this->pendingTransactionId = null;
        }
    }

    public function tryAgain(): void
    {
        $this->pendingTransactionId = null;
        $this->resetErrorBag();
    }

    /**
     * A tenant asking us to look into a stuck attempt — not a real
     * Safaricom cancellation (no such API exists), a request that triggers
     * an immediate status query and flags it for the admin team. Only
     * reachable once the attempt has been unresolved long enough that
     * asking is reasonable; MpesaInvestigationService enforces the rest
     * (not already resolved, not already requested) independently of this
     * check.
     */
    public function requestCancellation(int $transactionId): void
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        abort_if(! $tenancy, 404);

        $transaction = $this->unresolvedTransactionsQuery($tenancy)->find($transactionId);

        if (! $transaction) {
            return;
        }

        if ($transaction->created_at->gt(now()->subMinutes(10))) {
            $this->addError('phone', 'This attempt may still complete — please wait a little longer before requesting help.');

            return;
        }

        try {
            app(MpesaInvestigationService::class)->requestCancellation($transaction, Auth::user());
            session()->flash('status', 'We\'ve been notified and are looking into this — we\'ll update you once it\'s resolved.');
        } catch (InvalidArgumentException $e) {
            $this->addError('phone', $e->getMessage());
        }
    }

    public function openOfflineForm(): void
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        abort_if(! $tenancy, 404);

        // Final account-lifecycle phase: an offline claim is a new payment
        // obligation on a suspended landlord's books exactly as much as an
        // STK push is — PaymentGatewayService's own guard only ever sees
        // the online path, so this one needs its own.
        if ($tenant->currentTenancyLandlordIsSuspended()) {
            return;
        }

        // Phase 4 audit, Finding 4: an offline claim is just as much a
        // payment attempt as an STK push — it must not be usable to bypass
        // the same hard retry block.
        if ($this->unresolvedTransactionsQuery($tenancy)->exists()) {
            return;
        }

        $this->offline_method = 'bank';
        $this->offline_amount = $tenancy ? number_format($tenancy->balance(), 2, '.', '') : '';
        $this->offline_payment_date = now()->format('Y-m-d');
        $this->offline_reference = '';
        $this->offline_evidence = null;
        $this->showOfflineForm = true;
    }

    public function cancelOfflineForm(): void
    {
        $this->reset(['showOfflineForm', 'offline_reference', 'offline_evidence']);
    }

    /**
     * A tenant claiming they paid another way (bank, cash, the landlord's
     * own till/paybill) — this is only ever a claim until a landlord
     * confirms it. Never treated as money received: the allocated
     * Payment row(s) are created 'awaiting_landlord_confirmation' and
     * excluded from every ledger sum until then (see
     * PaymentAllocationService, RentCharge::recalculateStatus()).
     */
    public function submitOfflinePayment(): void
    {
        $data = $this->validate([
            'offline_method' => ['required', 'in:bank,cash,till,paybill,other'],
            'offline_amount' => ['required', 'numeric', 'min:1'],
            'offline_payment_date' => ['required', 'date', 'before_or_equal:today'],
            'offline_reference' => ['nullable', 'string', 'max:100'],
            'offline_evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        abort_if(! $tenancy, 404);

        // Re-checked here too, not only in openOfflineForm() — the view
        // toggle alone is never enforcement on its own.
        if ($tenant->currentTenancyLandlordIsSuspended()) {
            $this->addError('offline_amount', "This isn't available right now because your landlord's Kats Agencies account is suspended. Your tenancy and rent obligations are unaffected — please contact your landlord directly about how to pay while this platform restriction is in place.");

            return;
        }

        // Re-checked here too — never trust that the form could only have
        // been opened while clear (openOfflineForm() already guards this,
        // but the view toggle alone is never enforcement on its own).
        if ($this->unresolvedTransactionsQuery($tenancy)->exists()) {
            $this->addError('offline_amount', 'You already have a rent payment attempt that hasn\'t resolved yet. Please wait for it, or ask for help, before submitting another.');

            return;
        }

        $methodLabel = match ($data['offline_method']) {
            'bank' => 'bank transfer', 'cash' => 'cash', 'till' => "landlord's till",
            'paybill' => "landlord's paybill", default => 'another method',
        };

        $payments = app(PaymentAllocationService::class)->allocate(
            $tenancy,
            (float) $data['offline_amount'],
            now(),
            [
                'method' => $data['offline_method'] === 'bank' ? 'bank' : ($data['offline_method'] === 'cash' ? 'cash' : 'other'),
                'reference_number' => $data['offline_reference'] ?: null,
                'recorded_by' => Auth::id(),
                'notes' => "Tenant claims payment via {$methodLabel}, submitted ".now()->format('d M Y').'.',
                'source' => 'tenant_submitted_offline',
                'status' => 'awaiting_landlord_confirmation',
            ],
        );

        $firstPayment = $payments->first();

        if ($this->offline_evidence && $firstPayment) {
            $path = $this->offline_evidence->store('documents', 'local');

            Document::create([
                'documentable_type' => $firstPayment->getMorphClass(),
                'documentable_id' => $firstPayment->id,
                'category' => 'payment_confirmation',
                'original_filename' => $this->offline_evidence->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $this->offline_evidence->getMimeType(),
                'size' => $this->offline_evidence->getSize(),
                'uploaded_by' => Auth::id(),
            ]);
        }

        $tenancy->loadMissing('house.block.property');

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'payment_awaiting_confirmation',
            'A Tenant Reported a Payment — Needs Your Confirmation',
            "{$tenant->name} ({$tenancy->house->house_number}) says they paid KSh ".number_format((float) $data['offline_amount'], 2)." via {$methodLabel}. Please review and confirm or dispute it.",
            ['in_app', 'email'],
            $firstPayment,
        );

        $this->cancelOfflineForm();
        session()->flash('status', 'Payment submitted — your landlord will confirm it shortly. It will reflect in your balance once confirmed.');
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        $transaction = $this->ownPendingTransaction();

        // Almost always 0 or 1 — the hard retry block in pay()/
        // submitOfflinePayment() prevents a second unresolved attempt going
        // forward. More than one can only exist from data predating this
        // fix; the view falls back to a plain list in that case rather than
        // arbitrarily picking one to show.
        $unresolvedTransactions = $tenancy ? $this->unresolvedTransactionsQuery($tenancy)->oldest()->get() : collect();

        $landlordId = $tenancy?->house?->block?->property?->landlord_id;

        return view('livewire.tenant.pay-rent', [
            'transaction' => $transaction,
            'unresolvedTransactions' => $unresolvedTransactions,
            'fullBalance' => $tenancy?->balance() ?? 0,
            'monthAmount' => $this->monthAmount($tenancy),
            // Shown separately from the rent balance above — paying rent in
            // full must never read as "nothing else is owed" when the
            // deposit itself is still short.
            'depositOutstanding' => $tenancy?->depositOutstanding() ?? 0,
            'mpesaAvailable' => $landlordId ? (bool) app(PaymentGatewayService::class)->collectionModeFor($landlordId) : false,
        ]);
    }
}
