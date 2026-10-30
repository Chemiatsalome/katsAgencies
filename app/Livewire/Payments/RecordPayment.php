<?php

namespace App\Livewire\Payments;

use App\Models\Tenancy;
use App\Services\PaymentAllocationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;

class RecordPayment extends Component
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

    /**
     * Always available, never dependent on an outstanding charge existing —
     * a landlord can record a payment at any time, including a pure advance
     * with nothing currently due. Routed entirely through
     * PaymentAllocationService::allocate() (the same oldest-first splitter
     * the M-Pesa callback and tenant offline-claim flows already use)
     * instead of the previous single-charge picker:
     *   - the charge(s) a payment lands on are always derived server-side
     *     from this tenancy, never a client-submitted rent_charge_id;
     *   - an amount spanning several months is split across them correctly
     *     instead of landing entirely on one picked charge;
     *   - allocate() now self-heals the case where this tenancy has no
     *     RentCharge row at all yet, so this never produces an unlinked
     *     payment that silently never reflects in the balance.
     */
    public function save(): void
    {
        $this->authorize('update', $this->tenancy);

        $referenceRule = ['nullable', 'string', 'max:255'];

        // An M-Pesa receipt number is unique to that one transaction —
        // Safaricom never issues the same one twice. Checked only for the
        // mpesa method (a bank/cash reference has no such guarantee) and
        // only when one was actually given, this is what stops the same
        // real receipt from being recorded twice: once automatically via
        // the STK callback, once manually by a manager who didn't realize
        // it had already gone through.
        if ($this->method === 'mpesa' && $this->reference_number !== '') {
            $referenceRule[] = Rule::unique('payments', 'reference_number')->where('method', 'mpesa');
        }

        $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'in:mpesa,bank,cash,other'],
            'reference_number' => $referenceRule,
            'notes' => ['nullable', 'string'],
        ], [], ['reference_number' => 'M-Pesa receipt number']);

        app(PaymentAllocationService::class)->allocate(
            $this->tenancy,
            (float) $this->amount,
            Carbon::parse($this->payment_date),
            [
                'method' => $this->method,
                'reference_number' => $this->reference_number ?: null,
                'recorded_by' => Auth::id(),
                'notes' => $this->notes ?: null,
                'source' => 'manual_entry',
            ],
        );

        $this->reset(['amount', 'reference_number', 'notes']);
        $this->payment_date = now()->format('Y-m-d');

        $this->dispatch('payment-recorded');
    }

    public function render()
    {
        return view('livewire.payments.record-payment');
    }
}
