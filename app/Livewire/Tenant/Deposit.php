<?php

namespace App\Livewire\Tenant;

use App\Models\DepositDeduction;
use App\Services\DepositService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Deposit extends Component
{
    public ?int $disputingDeductionId = null;

    public string $dispute_reason = '';

    public function startDispute(int $deductionId): void
    {
        $deduction = $this->ownDeduction($deductionId);

        if ($deduction->isVoided() || $deduction->isDisputePending()) {
            session()->flash('error', 'This deduction can\'t be disputed right now.');

            return;
        }

        $this->disputingDeductionId = $deductionId;
        $this->dispute_reason = '';
    }

    public function cancelDispute(): void
    {
        $this->reset(['disputingDeductionId', 'dispute_reason']);
    }

    public function confirmDispute(): void
    {
        $deduction = $this->ownDeduction($this->disputingDeductionId);

        $this->validate([
            'dispute_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            app(DepositService::class)->disputeDeduction($deduction, $this->dispute_reason, Auth::user()->tenant->id);
        } catch (InvalidArgumentException $e) {
            $this->addError('dispute_reason', $e->getMessage());

            return;
        }

        $this->cancelDispute();
        session()->flash('status', 'Your dispute has been sent to your landlord.');
    }

    /** Only ever act on a deduction against the current tenant's own tenancy. */
    private function ownDeduction(?int $deductionId): DepositDeduction
    {
        $tenant = Auth::user()->tenant;

        return DepositDeduction::whereHas('tenancy', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->findOrFail($deductionId);
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->mostRecentTenancy();
        $tenancy?->load('house.block.property');

        if (! $tenancy) {
            return view('livewire.tenant.deposit', [
                'tenancy' => null,
            ]);
        }

        $service = app(DepositService::class);

        return view('livewire.tenant.deposit', [
            'tenancy' => $tenancy,
            'required' => (float) $tenancy->deposit_required,
            'paid' => (float) $tenancy->deposit_paid,
            'outstanding' => $service->outstanding($tenancy),
            'held' => $service->heldBalance($tenancy),
            'deducted' => $service->deductedTotal($tenancy),
            'refunded' => $service->refundedTotal($tenancy),
            'deductions' => $tenancy->depositDeductions()->with('documents')->orderByDesc('deduction_date')->get(),
            'payments' => $tenancy->depositPayments()->orderByDesc('payment_date')->get(),
            'refunds' => $tenancy->depositRefunds()->orderByDesc('refund_date')->get(),
        ]);
    }
}
