<?php

namespace App\Livewire\Tenant;

use App\Models\LateFeeWaiverRequest;
use App\Models\RentCharge;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Rent extends Component
{
    #[Url]
    public ?int $year = null;

    public ?int $waiverChargeId = null;

    public string $waiverReason = '';

    public function mount(): void
    {
        $this->year ??= (int) now()->year;
    }

    public function openWaiverForm(int $chargeId): void
    {
        $this->waiverChargeId = $chargeId;
        $this->waiverReason = '';
    }

    public function cancelWaiverForm(): void
    {
        $this->reset(['waiverChargeId', 'waiverReason']);
    }

    public function requestWaiver(): void
    {
        $tenant = Auth::user()->tenant;

        $charge = RentCharge::whereHas('tenancy', fn ($q) => $q->where('tenant_id', $tenant?->id))
            ->with('tenancy.house.block.property')
            ->findOrFail($this->waiverChargeId);

        if ((float) $charge->late_fee_amount <= 0) {
            $this->cancelWaiverForm();

            return;
        }

        if ($charge->pendingWaiverRequest()) {
            $this->addError('waiverReason', 'A request for this fee is already pending.');

            return;
        }

        $this->validate([
            'waiverReason' => ['nullable', 'string', 'max:1000'],
        ]);

        $waiverRequest = LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id,
            'tenancy_id' => $charge->tenancy_id,
            'requested_amount' => $charge->late_fee_amount,
            'reason' => $this->waiverReason ?: null,
            'status' => 'pending',
        ]);

        $landlord = $charge->tenancy->house->block->property->landlord;
        $periodLabel = \Illuminate\Support\Carbon::create($charge->period_year, $charge->period_month, 1)->format('F Y');

        app(NotificationService::class)->notify(
            $landlord,
            'late_fee_waiver_requested',
            'Late Fee Waiver Requested',
            "{$tenant->name} is requesting you waive their KSh ".number_format($charge->late_fee_amount, 2)." late fee for {$periodLabel}.".($this->waiverReason ? " Reason: {$this->waiverReason}" : ''),
            ['in_app'],
            // Tied to this specific request (not just the tenancy) so
            // resolving it can mark exactly this notification read without
            // also clearing a different, still-pending request's alert for
            // the same tenant.
            $waiverRequest,
        );

        $this->cancelWaiverForm();
        session()->flash('status', 'Your waiver request has been sent to your landlord.');
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        $tenancy?->load('house.block.property');

        $availableYears = $tenancy
            ? range((int) $tenancy->move_in_date->year, max((int) now()->year, (int) $tenancy->move_in_date->year))
            : [(int) now()->year];

        return view('livewire.tenant.rent', [
            'tenancy' => $tenancy,
            'availableYears' => array_reverse($availableYears),
            'rentCharges' => $tenancy
                ? $tenancy->rentCharges()->with('waiverRequests')->where('period_year', $this->year)->orderByDesc('period_month')->get()
                : collect(),
            'payments' => $tenancy
                ? $tenancy->payments()->latest('payment_date')->latest('id')->get()
                : collect(),
            // Same read-only credit breakdown as the landlord's Tenancies\Show
            // page — a tenant who overpaid shouldn't see a later charge as
            // fully unpaid when it's already covered.
            'advanceCreditAvailable' => $tenancy?->advanceCreditAvailable() ?? 0,
            'creditAppliedByChargeId' => $tenancy
                ? $tenancy->outstandingChargesWithCredit()->keyBy(fn (array $row) => $row['charge']->id)->map(fn (array $row) => $row['credit_applied'])
                : collect(),
        ]);
    }
}
