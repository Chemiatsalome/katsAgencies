<?php

namespace App\Livewire\Deposits;

use App\Models\DepositPayment;
use App\Models\Tenancy;
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

        DepositPayment::create([
            'tenancy_id' => $this->tenancy->id,
            'amount' => $this->amount,
            'payment_date' => $this->payment_date,
            'method' => $this->method,
            'reference_number' => $this->reference_number ?: null,
            'recorded_by' => Auth::id(),
            'notes' => $this->notes ?: null,
        ]);

        $this->reset(['amount', 'reference_number', 'notes']);
        $this->payment_date = now()->format('Y-m-d');

        $this->dispatch('deposit-recorded');
    }

    public function render()
    {
        return view('livewire.deposits.record-deposit');
    }
}
