<?php

namespace App\Livewire\Tenancies;

use App\Models\DepositRefund;
use App\Models\Tenancy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Show extends Component
{
    public Tenancy $tenancy;

    public bool $showMoveOutForm = false;

    public string $moveOutStatus = 'completed';

    public string $terminationReason = '';

    public bool $showRefundForm = false;

    public string $refund_amount = '';

    public string $refund_deductions = '0';

    public string $refund_reason = '';

    public function mount(Tenancy $tenancy): void
    {
        $this->authorize('view', $tenancy);
        $this->tenancy = $tenancy;
    }

    #[On('payment-recorded')]
    #[On('deposit-recorded')]
    public function refreshTenancy(): void
    {
        // Triggers a re-render; the render() method reloads all fresh data.
    }

    public function moveOut(): void
    {
        $this->authorize('update', $this->tenancy);

        $this->validate([
            'moveOutStatus' => ['required', 'in:completed,terminated'],
            'terminationReason' => [$this->moveOutStatus === 'terminated' ? 'required' : 'nullable', 'string', 'max:255'],
        ]);

        $this->tenancy->update([
            'status' => $this->moveOutStatus,
            'move_out_date' => now()->format('Y-m-d'),
            'termination_reason' => $this->moveOutStatus === 'terminated' ? $this->terminationReason : null,
        ]);

        $this->showMoveOutForm = false;
    }

    public function giveNotice(): void
    {
        $this->authorize('update', $this->tenancy);
        $this->tenancy->update(['status' => 'notice_given', 'notice_date' => now()->format('Y-m-d')]);
    }

    public function recordRefund(): void
    {
        $this->authorize('update', $this->tenancy);

        $this->validate([
            'refund_amount' => ['required', 'numeric', 'min:0'],
            'refund_deductions' => ['required', 'numeric', 'min:0'],
            'refund_reason' => ['nullable', 'string'],
        ]);

        DepositRefund::create([
            'tenancy_id' => $this->tenancy->id,
            'amount' => $this->refund_amount,
            'deductions' => $this->refund_deductions,
            'deduction_reason' => $this->refund_reason ?: null,
            'refund_date' => now()->format('Y-m-d'),
            'recorded_by' => Auth::id(),
        ]);

        $this->reset(['refund_amount', 'refund_deductions', 'refund_reason', 'showRefundForm']);
        $this->refund_deductions = '0';
    }

    public function render()
    {
        $this->tenancy->refresh();

        return view('livewire.tenancies.show', [
            'rentCharges' => $this->tenancy->rentCharges()->orderByDesc('period_year')->orderByDesc('period_month')->get(),
            'payments' => $this->tenancy->payments()->latest('payment_date')->latest('id')->get(),
            'depositPayments' => $this->tenancy->depositPayments()->latest('payment_date')->get(),
            'depositRefunds' => $this->tenancy->depositRefunds()->latest('refund_date')->get(),
        ]);
    }
}
