<?php

namespace App\Livewire\Tenants;

use App\Models\Block;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public string $status = 'all';

    #[Url]
    public int $perPage = 25;

    // TenantPolicy::viewAny() passes for any manager — the landlord-granted
    // tenants.view permission is the real gate, same as Contracts/Arrears.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasManagerPermission('tenants.view'), 403);
    }

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->resetPage();
    }

    /**
     * KNOWN FINANCIAL AUDIT-RETENTION LIMITATION (flagged during the Phase 4
     * audit, Finding 4 — deliberately left unchanged in that phase, pending
     * a separate decision; see that phase's final report for the full
     * reasoning):
     *
     * This only blocks deletion while an OCCUPYING tenancy exists. A tenant
     * whose tenancy has ended still hard-deletes here, and the existing FK
     * chain (tenants -> tenancies -> mpesa_transactions -> mpesa_status_queries,
     * each cascadeOnDelete()) means every M-Pesa transaction and its entire
     * query-history/investigation audit trail for that tenant is destroyed
     * along with it — including any already resolved 'success' transaction
     * tied to a real, settled payment. Payments/DepositPayments themselves
     * are NOT FK-cascaded (their tenancy_id references are left orphaned,
     * not deleted), but their mpesa_transaction_id back-reference and the
     * MpesaTransaction row it pointed to are gone, and nothing here asks
     * whether this tenant has any payment/financial history before allowing
     * the delete.
     *
     * This is a pre-existing characteristic of this feature, not something
     * Finding 4 introduced — mpesa_transactions.tenancy_id already cascaded
     * before this phase. Changing it (e.g. a restrictOnDelete() FK, or
     * checking financial history here) would alter this existing,
     * already-shipped delete workflow's behaviour for landlords/managers
     * using it today, so it is deliberately left alone pending an explicit,
     * separate decision — recommended options: (a) block deletion outright
     * for a tenant with any Payment/DepositPayment/MpesaTransaction history,
     * mirroring the OCCUPYING_STATUSES check above, or (b) replace the hard
     * delete with a soft-delete/anonymisation that retains the financial
     * rows while scrubbing personal data, which also sidesteps the FK
     * cascade entirely.
     */
    public function delete(Tenant $tenant): void
    {
        $this->authorize('delete', $tenant);

        if ($tenant->tenancies()->whereIn('status', Tenancy::OCCUPYING_STATUSES)->exists()) {
            session()->flash('status', 'This tenant has an active tenancy — end the tenancy before deleting their record.');

            return;
        }

        $tenant->delete();
    }

    public function render()
    {
        $user = Auth::user();

        $properties = Property::accessibleBy($user)->orderBy('name')->get(['id', 'name']);

        $blocksQuery = Block::accessibleBy($user)->when(
            $this->propertyId !== 'all',
            fn ($q) => $q->where('property_id', $this->propertyId)
        );
        $blocks = $blocksQuery->orderBy('name')->get(['id', 'name', 'property_id']);

        // Which tenancy represents each tenant's row in the table: whatever
        // status was picked, or their currently-active one by default.
        $displayScope = fn ($q) => $this->status !== 'all' ? $q->where('status', $this->status) : $q->active();

        $query = Tenant::with(['tenancies' => fn ($q) => $displayScope($q)->with('house.block.property')->latest('move_in_date')])
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")))
            ->when($this->status !== 'all', fn ($q) => $q->whereHas('tenancies', fn ($q2) => $q2->where('status', $this->status)))
            ->when($this->propertyId !== 'all', fn ($q) => $q->whereHas(
                'tenancies.house.block',
                fn ($q2) => $q2->where('property_id', $this->propertyId)
            ))
            ->when($this->blockId !== 'all', fn ($q) => $q->whereHas(
                'tenancies.house',
                fn ($q2) => $q2->where('block_id', $this->blockId)
            ))
            ->latest()
            ->accessibleBy($user);

        return view('livewire.tenants.index', [
            'tenants' => $query->paginate($this->perPage),
            'properties' => $properties,
            'blocks' => $blocks,
        ]);
    }
}
