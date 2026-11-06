<?php

namespace App\Livewire\Tenant;

use App\Models\Document;
use App\Models\MpesaTransaction;
use App\Services\DepositService;
use App\Services\MpesaService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.tenant')]
class PayDeposit extends Component
{
    use WithFileUploads;

    public string $phone = '';

    public string $amount = '';

    // Only ever set server-side, by pay() below, against the tenancy it
    // resolves there and there alone — #[Locked] refuses any client-sent
    // update to this property outright. checkStatus()/render() additionally
    // never trust the value alone: both re-scope the lookup to the
    // authenticated tenant's own tenancy. See audit Finding 1 (Phase 4),
    // mirroring the identical fix in PayRent.
    #[Locked]
    public ?int $pendingTransactionId = null;

    // "I already paid my deposit offline" — a separate claim, not an STK
    // push; stays out of the deposit ledger until a landlord confirms it
    // (see DepositPayment::needsLandlordConfirmation(),
    // DepositPaymentObserver). Mirrors PayRent's identical offline-claim
    // flow — the root cause of the previously-missing manual option here
    // was that DepositPayment had no confirmation lifecycle at all until
    // this phase added one (see migration
    // add_confirmation_workflow_to_deposit_payments_table).
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
        $outstanding = $tenancy?->depositOutstanding() ?? 0.0;

        $this->amount = $outstanding > 0 ? number_format($outstanding, 2, '.', '') : '';
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

        try {
            $landlordId = $tenancy->house->block->property->landlord_id;
            $gateway = app(PaymentGatewayService::class);

            $collectionMode = $gateway->collectionModeFor($landlordId);

            if (! $collectionMode) {
                $this->addError('phone', 'Online payment is not available for this property right now. Please contact your landlord about your deposit.');

                return;
            }

            $transaction = MpesaTransaction::create([
                'tenancy_id' => $tenancy->id,
                'initiated_by' => Auth::id(),
                'phone' => app(MpesaService::class)->normalizePhone($this->phone),
                'amount' => $this->amount,
                'purpose' => 'deposit',
                'collection_mode' => $collectionMode,
                'status' => 'pending',
            ]);

            $response = $gateway->initiateStkPush(
                $landlordId,
                $this->phone,
                (float) $this->amount,
                'Deposit-'.$tenancy->id,
                'Deposit payment'
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
     * independently of that. Scoped to this tenant's own tenancy; a
     * mismatched or missing id is indistinguishable from "no transaction"
     * to the caller.
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

            return;
        }

        if ($transaction->status === 'pending' && $transaction->created_at->lt(now()->subMinutes(3))) {
            $transaction->update(['status' => 'timeout']);
        }
    }

    public function tryAgain(): void
    {
        $this->pendingTransactionId = null;
        $this->resetErrorBag();
    }

    public function openOfflineForm(): void
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();

        $this->offline_method = 'bank';
        $this->offline_amount = $tenancy ? number_format($tenancy->depositOutstanding(), 2, '.', '') : '';
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
     * A tenant claiming they paid their deposit another way (bank, cash,
     * the landlord's own till/paybill) — this is only ever a claim until a
     * landlord confirms it. Never treated as held deposit: the
     * DepositPayment row is created 'awaiting_landlord_confirmation' and
     * excluded from Tenancy.deposit_paid until then (see
     * DepositPaymentObserver, mirroring PayRent::submitOfflinePayment()
     * exactly for the deposit ledger instead of rent).
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

        $methodLabel = match ($data['offline_method']) {
            'bank' => 'bank transfer', 'cash' => 'cash', 'till' => "landlord's till",
            'paybill' => "landlord's paybill", default => 'another method',
        };

        $depositPayment = app(DepositService::class)->recordPayment(
            $tenancy,
            (float) $data['offline_amount'],
            $data['offline_payment_date'],
            $data['offline_method'] === 'bank' ? 'bank' : ($data['offline_method'] === 'cash' ? 'cash' : 'other'),
            $data['offline_reference'] ?: null,
            "Tenant claims deposit payment via {$methodLabel}, submitted ".now()->format('d M Y').'.',
            Auth::id(),
            'awaiting_landlord_confirmation',
        );

        if ($this->offline_evidence) {
            $path = $this->offline_evidence->store('documents', 'local');

            Document::create([
                'documentable_type' => $depositPayment->getMorphClass(),
                'documentable_id' => $depositPayment->id,
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
            'deposit_payment_awaiting_confirmation',
            'A Tenant Reported a Deposit Payment — Needs Your Confirmation',
            "{$tenant->name} ({$tenancy->house->house_number}) says they paid KSh ".number_format((float) $data['offline_amount'], 2)." towards their deposit via {$methodLabel}. Please review and confirm or dispute it.",
            ['in_app', 'email'],
            $depositPayment,
        );

        $this->cancelOfflineForm();
        session()->flash('status', 'Deposit payment submitted — your landlord will confirm it shortly. It will reflect in your deposit balance once confirmed.');
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        $transaction = $this->ownPendingTransaction();

        $landlordId = $tenancy?->house?->block?->property?->landlord_id;

        return view('livewire.tenant.pay-deposit', [
            'transaction' => $transaction,
            'outstanding' => $tenancy?->depositOutstanding() ?? 0,
            'mpesaAvailable' => $landlordId ? (bool) app(PaymentGatewayService::class)->collectionModeFor($landlordId) : false,
        ]);
    }
}
