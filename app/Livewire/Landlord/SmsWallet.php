<?php

namespace App\Livewire\Landlord;

use App\Models\MpesaTransaction;
use App\Models\SmsWalletEntry;
use App\Services\SmsWalletService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.admin')]
class SmsWallet extends Component
{
    public string $phone = '';

    public string $amount = '';

    public ?int $pendingTransactionId = null;

    public function mount(): void
    {
        $this->phone = (string) (Auth::user()->phone ?? '');
    }

    public function topUp(): void
    {
        $data = $this->validate([
            'phone' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:20'],
        ]);

        try {
            $response = app(SmsWalletService::class)->initiateTopUp(
                Auth::id(), Auth::id(), $data['phone'], (float) $data['amount']
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $transaction = MpesaTransaction::where('checkout_request_id', $response['CheckoutRequestID'] ?? null)->latest()->first();
        $this->pendingTransactionId = $transaction?->id;
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

        // STK pushes that never get a callback (phone unreachable, app
        // killed, etc.) shouldn't poll forever — same 3-minute timeout as
        // PayRent/PayDeposit::checkStatus().
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
        $landlordId = Auth::id();
        $wallet = app(SmsWalletService::class);

        return view('livewire.landlord.sms-wallet', [
            'balance' => $wallet->balance($landlordId),
            'rate' => $wallet->rate(),
            'entries' => SmsWalletEntry::where('landlord_id', $landlordId)->latest()->limit(50)->get(),
            'pendingTransaction' => $this->pendingTransactionId ? MpesaTransaction::find($this->pendingTransactionId) : null,
        ]);
    }
}
