<?php

namespace App\Livewire\RentCharges;

use App\Models\Payment;
use App\Models\Property;
use App\Models\RentCharge;
use App\Services\RentScheduleService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public int $year;

    #[Url]
    public int $month;

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public int $perPage = 25;

    // Payment modal state — shared by both "Mark as Paid" (prefilled with the
    // full balance) and "Record Payment" (landlord enters any amount,
    // including partial), since both just create a Payment row.
    public ?int $payingChargeId = null;

    public string $payment_amount = '';

    public string $payment_date;

    public string $payment_method = 'mpesa';

    public string $payment_reference = '';

    public string $payment_notes = '';

    public function mount(): void
    {
        // #[Url] already hydrates year/month from the query string before mount()
        // runs — only default them when the visitor didn't deep-link to a period.
        $this->year = (int) (request()->query('year') ?: now()->year);
        $this->month = (int) (request()->query('month') ?: now()->month);
        $this->payment_date = now()->format('Y-m-d');
    }

    public function updatedPropertyId(): void
    {
        $this->resetPage();
    }

    private function chargeForModal(): ?RentCharge
    {
        if (! $this->payingChargeId) {
            return null;
        }

        $charge = RentCharge::with('tenancy.tenant')->find($this->payingChargeId);
        if ($charge) {
            $this->authorize('update', $charge->tenancy);
        }

        return $charge;
    }

    public function markPaid(int $chargeId): void
    {
        $charge = RentCharge::findOrFail($chargeId);
        $this->authorize('update', $charge->tenancy);

        $this->payingChargeId = $chargeId;
        $this->payment_amount = number_format((float) $charge->amount_expected - (float) $charge->amount_paid, 2, '.', '');
        $this->payment_date = now()->format('Y-m-d');
        $this->payment_method = 'mpesa';
        $this->payment_reference = '';
        $this->payment_notes = '';
    }

    public function openPaymentModal(int $chargeId): void
    {
        $charge = RentCharge::findOrFail($chargeId);
        $this->authorize('update', $charge->tenancy);

        $this->payingChargeId = $chargeId;
        $this->payment_amount = '';
        $this->payment_date = now()->format('Y-m-d');
        $this->payment_method = 'mpesa';
        $this->payment_reference = '';
        $this->payment_notes = '';
    }

    public function revertHistoricalPayment(int $chargeId): void
    {
        $charge = RentCharge::findOrFail($chargeId);
        $this->authorize('update', $charge->tenancy);

        $reverted = app(RentScheduleService::class)->revertHistoricalPayment($charge, Auth::user());

        session()->flash('status', $reverted ? 'Marked as unpaid.' : 'This record has no historical payment to revert.');
    }

    /** Cached per landlord within the request — cheap to call once per row in the table. */
    public function financialStartDateFor(RentCharge $charge): \Illuminate\Support\Carbon
    {
        return app(RentScheduleService::class)->financialStartDate(
            $charge->tenancy->house->block->property->landlord_id
        );
    }

    public function closePaymentModal(): void
    {
        $this->reset(['payingChargeId', 'payment_amount', 'payment_reference', 'payment_notes']);
        $this->payment_date = now()->format('Y-m-d');
    }

    public function recordPayment(): void
    {
        $charge = RentCharge::findOrFail($this->payingChargeId);
        $this->authorize('update', $charge->tenancy);

        $this->validate([
            'payment_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'in:mpesa,bank,cash,other'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'payment_notes' => ['nullable', 'string'],
        ]);

        // Never overwrites — every payment is its own row, so partial and
        // multiple payments simply accumulate. RentCharge::amount_paid and
        // status are kept in sync by PaymentObserver on every Payment write.
        Payment::create([
            'tenancy_id' => $charge->tenancy_id,
            'rent_charge_id' => $charge->id,
            'amount' => $this->payment_amount,
            'payment_date' => $this->payment_date,
            'method' => $this->payment_method,
            'reference_number' => $this->payment_reference ?: null,
            'recorded_by' => Auth::id(),
            'notes' => $this->payment_notes ?: null,
            'source' => 'manual_entry',
        ]);

        session()->flash('status', 'Payment recorded.');
        $this->closePaymentModal();
    }

    public function render()
    {
        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');
        $landlordId = $isAdmin ? null : $user->id;

        $propertiesQuery = $isAdmin ? Property::query() : Property::forLandlord($user->id);
        $properties = $propertiesQuery->orderBy('name')->get(['id', 'name']);

        $propertyFilter = $this->propertyId !== 'all' ? (int) $this->propertyId : null;

        app(RentScheduleService::class)->ensureChargesForPeriod($landlordId, $propertyFilter, $this->year, $this->month);

        $query = RentCharge::forPeriod($this->year, $this->month)
            ->with(['tenancy.tenant', 'tenancy.house.block.property', 'payments'])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($propertyFilter, fn ($q) => $q->whereHas('tenancy.house.block', fn ($q2) => $q2->where('property_id', $propertyFilter)));

        if (! $isAdmin) {
            $query->whereHas('tenancy.house.block.property', fn ($q) => $q->where('landlord_id', $user->id));
        }

        return view('livewire.rent-charges.index', [
            'charges' => $query->orderBy('due_date')->paginate($this->perPage),
            'properties' => $properties,
            'availableYears' => app(RentScheduleService::class)->availableYears($landlordId),
            'payingCharge' => $this->chargeForModal(),
        ]);
    }
}
