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
        // The one carve-out finance permission a manager can be granted — view
        // only. Recording/reverting payments stays landlord-only regardless
        // (enforced separately below, not by this permission).
        abort_unless(Auth::user()->hasManagerPermission('finance.view_rent_status'), 403);

        // #[Url] already hydrates year/month from the query string before mount()
        // runs — only default them when the visitor didn't deep-link to a period.
        $this->year = (int) (request()->query('year') ?: now()->year);
        $this->month = (int) (request()->query('month') ?: now()->month);
        $this->payment_date = now()->format('Y-m-d');
    }

    // Recording or reverting a payment is never delegable to a manager, no
    // matter what permissions the landlord has granted — the landlord's own
    // TenancyPolicy::update() now permits managers with tenancies.terminate,
    // which is a different action entirely, so this check must be separate.
    private function guardLandlordOnly(): void
    {
        abort_unless(Auth::user()->hasRole('landlord'), 403);
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
            $this->guardLandlordOnly();
            $this->authorize('update', $charge->tenancy);
        }

        return $charge;
    }

    public function markPaid(int $chargeId): void
    {
        $this->guardLandlordOnly();
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
        $this->guardLandlordOnly();
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
        $this->guardLandlordOnly();
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
        $this->guardLandlordOnly();
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
        $landlordId = $user->landlordId();

        $properties = Property::accessibleBy($user)->orderBy('name')->get(['id', 'name']);

        $propertyFilter = $this->propertyId !== 'all' ? (int) $this->propertyId : null;

        app(RentScheduleService::class)->ensureChargesForPeriod($landlordId, $propertyFilter, $this->year, $this->month);

        $query = RentCharge::forPeriod($this->year, $this->month)
            ->with(['tenancy.tenant', 'tenancy.house.block.property', 'payments'])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($propertyFilter, fn ($q) => $q->whereHas('tenancy.house.block', fn ($q2) => $q2->where('property_id', $propertyFilter)));

        if (! $isAdmin) {
            $query->whereHas('tenancy.house', fn ($q) => $q->accessibleBy($user));
        }

        $charges = $query->orderBy('due_date')->paginate($this->perPage);

        // Computed only for tenancies that actually have credit (most won't,
        // so this stays cheap) — each tenancy's own oldest-first breakdown,
        // same shared calculation as Tenancies\Show/Tenant\Rent, so this
        // page's numbers never disagree with either of those.
        $creditByChargeId = $charges->getCollection()
            ->pluck('tenancy')
            ->filter()
            ->unique('id')
            ->filter(fn ($tenancy) => $tenancy->advanceCreditAvailable() > 0)
            ->flatMap(fn ($tenancy) => $tenancy->outstandingChargesWithCredit())
            ->keyBy(fn (array $row) => $row['charge']->id)
            ->map(fn (array $row) => $row['credit_applied']);

        return view('livewire.rent-charges.index', [
            'charges' => $charges,
            'creditByChargeId' => $creditByChargeId,
            'properties' => $properties,
            'availableYears' => app(RentScheduleService::class)->availableYears($landlordId),
            'payingCharge' => $this->chargeForModal(),
        ]);
    }
}
