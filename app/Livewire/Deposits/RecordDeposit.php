<?php

namespace App\Livewire\Deposits;

use App\Models\Tenancy;
use App\Services\DepositService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class RecordDeposit extends Component
{
    public Tenancy $tenancy;

    public string $amount = '';

    public string $payment_date;

    public string $method = 'mpesa';

    public string $reference_number = '';

    public string $notes = '';

    public function mount(Tenancy $tenancy): void
    {
        $this->tenancy = $tenancy;
        $this->payment_date = now()->format('Y-m-d');
    }

    public function save(): void
    {
        $this->authorize('update', $this->tenancy);

        $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'in:mpesa,bank,cash,other'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        app(DepositService::class)->recordPayment(
            $this->tenancy,
            (float) $this->amount,
            $this->payment_date,
            $this->method,
            $this->reference_number ?: null,
            $this->notes ?: null,
            Auth::id(),
        );

        $this->reset(['amount', 'reference_number', 'notes']);
        $this->payment_date = now()->format('Y-m-d');

        $this->dispatch('deposit-recorded');
    }

    public function render()
    {
        return view('livewire.deposits.record-deposit');
    }
}
