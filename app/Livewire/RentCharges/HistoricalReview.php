<?php

namespace App\Livewire\RentCharges;

use App\Models\Block;
use App\Models\Property;
use App\Models\RentCharge;
use App\Services\RentScheduleService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

#[Layout('layouts.admin')]
class HistoricalReview extends Component
{
    use WithPagination;

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public int $year;

    /** @var int[] */
    public array $selected = [];

    public bool $selectAll = false;

    public function mount(): void
    {
        $this->year = (int) (request()->query('year') ?: now()->year);
    }

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->resetPage();
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value ? $this->revertibleCharges()->pluck('id')->all() : [];
    }

    public function revertOne(int $chargeId): void
    {
        $charge = RentCharge::findOrFail($chargeId);
        $this->authorize('update', $charge->tenancy);

        app(RentScheduleService::class)->revertHistoricalPayment($charge, Auth::user());

        $this->selected = array_values(array_diff($this->selected, [$chargeId]));
        session()->flash('status', 'Reverted to unpaid.');
    }

    public function revertSelected(): void
    {
        if (empty($this->selected)) {
            return;
        }

        $service = app(RentScheduleService::class);
        $user = Auth::user();
        $reverted = 0;

        foreach (RentCharge::with('tenancy.house.block.property')->whereIn('id', $this->selected)->get() as $charge) {
            // Defensive re-check per row — never trust the client-selected id
            // list alone for a financial-correction bulk action.
            if (! $charge->isRevertible()) {
                continue;
            }

            $this->authorize('update', $charge->tenancy);

            if ($service->revertHistoricalPayment($charge, $user)) {
                $reverted++;
            }
        }

        $this->selected = [];
        $this->selectAll = false;
        session()->flash('status', "Reverted {$reverted} historical record(s) to unpaid.");
    }

    /** Every currently-revertible (historically paid) charge matching the active filters, unpaginated — used for select-all and the bulk action's re-check. */
    private function revertibleCharges()
    {
        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');
        $propertyFilter = $this->propertyId !== 'all' ? (int) $this->propertyId : null;
        $blockFilter = $this->blockId !== 'all' ? (int) $this->blockId : null;

        return RentCharge::with('payments', 'tenancy.tenant', 'tenancy.house.block.property')
            ->where('period_year', $this->year)
            ->where('status', 'paid')
            ->when($propertyFilter, fn ($q) => $q->whereHas('tenancy.house.block', fn ($q2) => $q2->where('property_id', $propertyFilter)))
            ->when($blockFilter, fn ($q) => $q->whereHas('tenancy.house', fn ($q2) => $q2->where('block_id', $blockFilter)))
            ->when(! $isAdmin, fn ($q) => $q->whereHas('tenancy.house.block.property', fn ($q2) => $q2->where('landlord_id', $user->id)))
            ->get()
            ->filter(fn (RentCharge $c) => $c->isRevertible())
            ->sortBy([['tenancy.tenant.name', 'asc'], ['period_month', 'asc']])
            ->values();
    }

    public function render()
    {
        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');
        $landlordId = $isAdmin ? null : $user->id;

        $properties = ($isAdmin ? Property::query() : Property::forLandlord($user->id))->orderBy('name')->get(['id', 'name']);
        $blocks = $this->propertyId !== 'all' ? Block::where('property_id', (int) $this->propertyId)->orderBy('name')->get(['id', 'name']) : collect();

        $recentReverts = Activity::where('log_name', 'financial_corrections')
            ->when(! $isAdmin, fn ($q) => $q->whereHasMorph('subject', [\App\Models\Payment::class], function ($q2) use ($user) {
                $q2->whereHas('tenancy.house.block.property', fn ($q3) => $q3->where('landlord_id', $user->id));
            }))
            ->latest()
            ->limit(10)
            ->get();

        return view('livewire.rent-charges.historical-review', [
            'properties' => $properties,
            'blocks' => $blocks,
            'availableYears' => app(RentScheduleService::class)->availableYears($landlordId),
            'charges' => $this->revertibleCharges(),
            'recentReverts' => $recentReverts,
        ]);
    }
}
