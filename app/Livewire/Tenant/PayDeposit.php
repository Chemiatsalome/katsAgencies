<?php

namespace App\Livewire\Tenant;

use App\Models\MpesaTransaction;
use App\Services\MpesaService;
use App\Services\PaymentGatewayService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.tenant')]
class PayDeposit extends Component
{
    public string $phone = '';

    public string $amount = '';

    public ?int $pendingTransactionId = null;

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

            $transaction = MpesaTransaction::create([
                'tenancy_id' => $tenancy->id,
                'initiated_by' => Auth::id(),
                'phone' => app(MpesaService::class)->normalizePhone($this->phone),
                'amount' => $this->amount,
                'purpose' => 'deposit',
                'collection_mode' => $gateway->collectionModeFor($landlordId),
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

        if ($transaction->status === 'pending' && $transaction->created_at->lt(now()->subMinutes(3))) {
            $transaction->update(['status' => 'timeout']);
        }
    }

    public function tryAgain(): void
    {
        $this->pendingTransactionId = null;
        $this->resetErrorBag();
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        $transaction = $this->pendingTransactionId ? MpesaTransaction::find($this->pendingTransactionId) : null;

        return view('livewire.tenant.pay-deposit', [
            'transaction' => $transaction,
            'outstanding' => $tenancy?->depositOutstanding() ?? 0,
        ]);
    }
}
