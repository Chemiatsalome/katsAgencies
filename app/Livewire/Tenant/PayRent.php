<?php

namespace App\Livewire\Tenant;

use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.tenant')]
class PayRent extends Component
{
    public string $phone = '';

    /** 'full' | 'month' | 'custom' */
    public string $paymentOption = 'full';

    public string $amount = '';

    public ?int $pendingTransactionId = null;

    public function mount(): void
    {
        $tenant = Auth::user()->tenant;
        $this->phone = $tenant?->phone ?? '';

        $tenancy = $tenant?->tenancies()->active()->first();

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
        $tenancy = $tenant?->tenancies()->active()->first();

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
        $tenancy = $tenant?->tenancies()->active()->first();
        abort_if(! $tenancy, 404);

        try {
            $mpesa = app(MpesaService::class);

            // Which specific rent charge(s) this settles is decided at callback
            // time against whatever is actually outstanding then (oldest first) —
            // a lump sum can span several months, so it isn't pinned to one charge here.
            $transaction = MpesaTransaction::create([
                'tenancy_id' => $tenancy->id,
                'initiated_by' => Auth::id(),
                'phone' => $mpesa->normalizePhone($this->phone),
                'amount' => $this->amount,
                'status' => 'pending',
            ]);

            $response = $mpesa->stkPush(
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

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->tenancies()->active()->first();
        $transaction = $this->pendingTransactionId ? MpesaTransaction::find($this->pendingTransactionId) : null;

        return view('livewire.tenant.pay-rent', [
            'transaction' => $transaction,
            'fullBalance' => $tenancy?->balance() ?? 0,
            'monthAmount' => $this->monthAmount($tenancy),
        ]);
    }
}
