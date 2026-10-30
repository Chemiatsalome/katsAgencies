<?php

namespace App\Livewire\Deposits;

use App\Models\Block;
use App\Models\Property;
use App\Models\Tenancy;
use App\Services\DepositService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public int $perPage = 25;

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $search = '';

    // No manager permission unlocks this page — deposit ledgers stay
    // landlord-only regardless of what else a manager has been granted.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('landlord'), 403);
    }

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
    }

    public function render()
    {
        $landlordId = Auth::id();
        $service = app(DepositService::class);

        $properties = Property::where('landlord_id', $landlordId)->orderBy('name')->get(['id', 'name']);

        $blocksQuery = Block::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->when($this->propertyId !== 'all', fn ($q) => $q->where('property_id', $this->propertyId));
        $blocks = $blocksQuery->orderBy('name')->get(['id', 'name', 'property_id']);

        $rows = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->when($this->propertyId !== 'all', fn ($q) => $q->whereHas(
                'house.block',
                fn ($q2) => $q2->where('property_id', $this->propertyId)
            ))
            ->when($this->blockId !== 'all', fn ($q) => $q->whereHas(
                'house',
                fn ($q2) => $q2->where('block_id', $this->blockId)
            ))
            ->when($this->search, fn ($q) => $q->whereHas(
                'tenant',
                fn ($q2) => $q2->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")
            ))
            ->with(['tenant', 'house.block.property', 'depositPayments', 'depositRefunds', 'depositDeductions'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Tenancy $tenancy) use ($service) {
                return (object) [
                    'tenancy' => $tenancy,
                    'required' => (float) $tenancy->deposit_required,
                    'paid' => (float) $tenancy->deposit_paid,
                    'outstanding' => $service->outstanding($tenancy),
                    'refunded' => $service->refundedTotal($tenancy),
                    'deducted' => $service->deductedTotal($tenancy),
                    'heldBalance' => $service->heldBalance($tenancy),
                    'refundStatus' => $tenancy->depositRefundStatus(),
                ];
            });

        if ($this->status === 'outstanding') {
            $rows = $rows->filter(fn ($row) => $row->outstanding > 0)->values();
        } elseif ($this->status === 'settled') {
            $rows = $rows->filter(fn ($row) => $row->outstanding <= 0)->values();
        }

        $totalHeld = $rows->sum('heldBalance');
        $totalOutstanding = $rows->sum('outstanding');
        $notRefundedCount = $rows->where('refundStatus', 'not_refunded')->count()
            + $rows->where('refundStatus', 'partially_refunded')->count();

        $page = $this->getPage();
        $paginated = new LengthAwarePaginator(
            $rows->forPage($page, $this->perPage)->values(),
            $rows->count(),
            $this->perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('livewire.deposits.index', [
            'rows' => $paginated,
            'properties' => $properties,
            'blocks' => $blocks,
            'totalHeld' => $totalHeld,
            'totalOutstanding' => $totalOutstanding,
            'notRefundedCount' => $notRefundedCount,
        ]);
    }
}
