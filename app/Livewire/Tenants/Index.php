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
     * F2 (account lifecycle audit, fixing the limitation previously flagged
     * here under Phase 4 Finding 4): used to only block deletion while an
     * OCCUPYING tenancy existed. A tenant whose tenancy had already ended
     * could still hard-delete here, destroying a real financial/
     * contractual trail — rent charges, payments, deposit payments/
     * refunds/deductions, contracts, M-Pesa transactions and their
     * investigation history — every one of them cascadeOnDelete() against
     * tenancies.id, which itself cascades from tenants.id. (The removed
     * version of this comment claimed Payments/DepositPayments were merely
     * orphaned rather than deleted; tracing the actual migrations —
     * 2026_08_09_162621_create_payments_table.php and
     * 2026_08_09_162622_create_deposit_payments_table.php — shows both are
     * cascadeOnDelete() on tenancy_id, so that was incorrect: they are
     * destroyed along with the tenancy, not left behind.)
     *
     * Now blocks deletion whenever the tenant has ANY tenancy at all, not
     * just an occupying one — any tenancy may have exactly that trail hung
     * off it. A tenant who has never had a tenancy has nothing of that kind
     * to lose, so deletion stays available for that genuinely unused case,
     * unchanged from today's behaviour.
     *
     * This guard lives here, not as a database-level restrictOnDelete() on
     * tenancies.tenant_id, deliberately: Tenant::booted()'s own deleting
     * hook already performs a deliberate, cascade-aware cleanup (physical
     * documents, dangling AppNotification rows) whenever a Tenant is
     * actually deleted by any code path, and
     * OrphanedNotificationCleanupTest::test_deleting_a_tenant_cleans_up_notifications_that_would_otherwise_dangle()
     * already exercises $tenant->delete() directly on a tenant WITH a real
     * tenancy and expects it to succeed. This Livewire action is the one
     * user-facing, policy-governed entry point the audit actually found
     * reachable — restricting the FK itself would also block that existing,
     * intentional model-level test and any other legitimate direct-delete
     * path, for no real additional protection since no other application
     * code deletes a Tenant today.
     */
    public function delete(Tenant $tenant): void
    {
        $this->authorize('delete', $tenant);

        if ($tenant->tenancies()->whereIn('status', Tenancy::OCCUPYING_STATUSES)->exists()) {
            session()->flash('status', 'This tenant has an active tenancy — end the tenancy before deleting their record.');

            return;
        }

        if ($tenant->tenancies()->exists()) {
            session()->flash('status', 'This tenant has historical tenancy, payment, deposit or contract records that must be retained — their record cannot be permanently deleted.');

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
