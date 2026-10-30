<?php

namespace App\Livewire\Tenant;

use App\Models\Document;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Services\MpesaService;
use App\Services\NotificationService;
use App\Services\PaymentAllocationService;
use App\Services\PaymentGatewayService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
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

        try {
            $landlordId = $tenancy->house->block->property->landlord_id;
            $gateway = app(PaymentGatewayService::class);

            // Which specific rent charge(s) this settles is decided at callback
            // time against whatever is actually outstanding then (oldest first) —
            // a lump sum can span several months, so it isn't pinned to one charge here.
            $transaction = MpesaTransaction::create([
                'tenancy_id' => $tenancy->id,
                'initiated_by' => Auth::id(),
                'phone' => app(MpesaService::class)->normalizePhone($this->phone),
                'amount' => $this->amount,
                'collection_mode' => $gateway->collectionModeFor($landlordId),
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

    public function checkStatus(): void
    {
        if (! $this->pendingTransactionId) {
            return;
        }

        $transaction = MpesaTransaction::find($this->pendingTransactionId);

        if (! $transaction) {
            $this->pendingTransactionId = null;

            return;
        }

        // STK pushes that never get a callback (phone unreachable, app killed, etc.)
        // shouldn't poll forever — give the tenant a clear timeout instead.
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
        $transaction = $this->pendingTransactionId ? MpesaTransaction::find($this->pendingTransactionId) : null;

        return view('livewire.tenant.pay-rent', [
            'transaction' => $transaction,
            'fullBalance' => $tenancy?->balance() ?? 0,
            'monthAmount' => $this->monthAmount($tenancy),
            // Shown separately from the rent balance above — paying rent in
            // full must never read as "nothing else is owed" when the
            // deposit itself is still short.
            'depositOutstanding' => $tenancy?->depositOutstanding() ?? 0,
        ]);
    }
}
