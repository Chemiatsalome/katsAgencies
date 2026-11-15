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
        // Landlord-only, same boundary as the six deposit-financial actions
        // in Tenancies\Show (see that component's authorizeDepositFinance())
        // — recording an initial deposit payment is exactly as much a
        // deposit-financial action as a refund or deduction, and
        // TenancyPolicy::update() alone grants this to any manager holding
        // tenancies.terminate, an ordinary tenancy-management permission
        // with no finance connotation.
        $this->authorize('update', $this->tenancy);
        abort_unless(Auth::user()->hasRole('landlord'), 403);

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
