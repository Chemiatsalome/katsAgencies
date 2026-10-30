<?php

namespace App\Livewire\Arrears;

use App\Models\AppNotification;
use App\Models\Block;
use App\Models\LateFeeWaiverRequest;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Services\NotificationService;
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
    public string $minDaysOverdue = 'all';

    #[Url]
    public string $search = '';

    public bool $showNoteForm = false;

    public ?int $noteTenancyId = null;

    public string $noteText = '';

    public bool $notifyTenant = false;

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->resetPage();
    }

    // The same carve-out finance permission RentCharges\Index gates on —
    // arrears is just outstanding rent status, viewed differently.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasManagerPermission('finance.view_rent_status'), 403);
    }

    public function sendReminder(int $tenancyId): void
    {
        $tenancy = Tenancy::with(['tenant', 'house.block.property'])->findOrFail($tenancyId);
        $this->authorize('update', $tenancy);

        $outstanding = $tenancy->rentCharges()->outstanding()->get()->sum(fn (RentCharge $c) => $c->amount_expected - $c->amount_paid);

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'rent_reminder',
            'Rent Payment Reminder',
            "This is a reminder that your rent for {$tenancy->house->house_number} at {$tenancy->house->block->property->name} is overdue by KSh ".number_format($outstanding, 2).'. Please make payment as soon as possible.',
            ['in_app', 'email'],
            $tenancy
        );

        session()->flash('status', "Reminder sent to {$tenancy->tenant->name}.");
    }

    /** Waives every late fee currently on this tenancy's outstanding charges at once — the Arrears row is already an aggregate across periods, so the action matches that. */
    public function waiveLateFees(int $tenancyId): void
    {
        $tenancy = Tenancy::with('tenant')->findOrFail($tenancyId);
        $this->authorize('update', $tenancy);

        $charges = $tenancy->rentCharges()->outstanding()->where('late_fee_amount', '>', 0)->get();

        $totalWaived = $charges->sum(fn (RentCharge $c) => $c->waiveLateFee(Auth::user()->name));

        if ($totalWaived > 0) {
            session()->flash('status', "Waived KSh ".number_format($totalWaived, 2)." in late fees for {$tenancy->tenant->name}.");
        }
    }

    public function acceptWaiverRequest(int $requestId): void
    {
        $user = Auth::user();
        $request = LateFeeWaiverRequest::accessibleBy($user)->pending()
            ->with('rentCharge', 'tenancy.tenant')
            ->findOrFail($requestId);

        $this->authorize('update', $request->tenancy);

        $waived = $request->rentCharge->waiveLateFee($user->name);

        $request->update([
            'status' => 'accepted',
            'decided_by' => $user->id,
            'decided_at' => now(),
        ]);

        $this->markRequestNotificationRead($request);

        app(NotificationService::class)->notify(
            $request->tenancy->tenant,
            'late_fee_waiver_decided',
            'Late Fee Waiver Approved',
            "Your request to waive the KSh ".number_format($waived, 2)." late fee was approved.",
            ['in_app'],
            $request->tenancy,
        );

        session()->flash('status', "Waiver approved for {$request->tenancy->tenant->name}.");
    }

    public function rejectWaiverRequest(int $requestId, ?string $reason = null): void
    {
        $user = Auth::user();
        $request = LateFeeWaiverRequest::accessibleBy($user)->pending()
            ->with('tenancy.tenant')
            ->findOrFail($requestId);

        $this->authorize('update', $request->tenancy);

        $reason = $reason !== null ? trim($reason) : null;

        $request->update([
            'status' => 'rejected',
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_note' => $reason ?: null,
        ]);

        $this->markRequestNotificationRead($request);

        app(NotificationService::class)->notify(
            $request->tenancy->tenant,
            'late_fee_waiver_decided',
            'Late Fee Waiver Declined',
            "Your request to waive the KSh ".number_format($request->requested_amount, 2)." late fee was declined.".($reason ? " Reason: {$reason}" : ''),
            ['in_app'],
            $request->tenancy,
        );

        session()->flash('status', "Waiver declined for {$request->tenancy->tenant->name}.");
    }

    /**
     * The notification that alerted the landlord to this request is tied to
     * it via related_type/related_id (see Tenant\Rent::requestWaiver) — once
     * the landlord has acted on it, that alert no longer needs attention, so
     * the bell badge count should drop without a separate manual "mark read".
     */
    private function markRequestNotificationRead(LateFeeWaiverRequest $request): void
    {
        AppNotification::where('related_type', LateFeeWaiverRequest::class)
            ->where('related_id', $request->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function openNoteForm(int $tenancyId): void
    {
        $this->authorize('update', Tenancy::findOrFail($tenancyId));
        $this->noteTenancyId = $tenancyId;
        $this->noteText = '';
        $this->notifyTenant = false;
        $this->showNoteForm = true;
    }

    public function addNote(): void
    {
        $this->validate(['noteText' => ['required', 'string', 'max:1000']]);

        $tenancy = Tenancy::with(['tenant', 'house.block.property'])->findOrFail($this->noteTenancyId);
        $this->authorize('update', $tenancy);

        $tenant = $tenancy->tenant;
        $stamp = now()->format('d M Y H:i');
        $entry = "[{$stamp}] Arrears note: {$this->noteText}";
        $tenant->update(['notes' => trim(($tenant->notes ? $tenant->notes."\n" : '').$entry)]);

        // "Add Note" by itself is a private landlord memo (e.g. "promised to
        // pay by Friday") — it was never visible to the tenant at all, which
        // is correct for that use case but meant there was no way to
        // actually message the tenant from this screen despite the button
        // reading like it would. This checkbox is the explicit opt-in for
        // that — only checking it creates something the tenant can see.
        if ($this->notifyTenant) {
            app(NotificationService::class)->notify(
                $tenant,
                'landlord_note',
                'Message from your landlord',
                $this->noteText,
                ['in_app'],
                $tenancy,
            );
        }

        $this->showNoteForm = false;
        session()->flash('status', $this->notifyTenant ? 'Note saved and sent to the tenant.' : 'Note added to tenant record.');
    }

    public function render()
    {
        $user = Auth::user();

        // Opportunistic — same reasoning as the dashboard: a landlord who
        // just enabled late fees in Settings shouldn't have to wait for the
        // nightly scheduled command (which, without a cron/supervisor
        // process actually configured to run it, may never fire) before
        // seeing it take effect. Guarded on landlordId() being resolvable —
        // passing null here would mean "every landlord platform-wide",
        // which a single manager's page view must never trigger.
        if ($landlordId = $user->landlordId()) {
            app(\App\Services\RentScheduleService::class)->applyLateFees($landlordId);
        }

        $pendingWaiverRequests = LateFeeWaiverRequest::accessibleBy($user)->pending()
            ->with('tenancy.tenant', 'tenancy.house.block.property')
            ->latest()
            ->get();

        $properties = Property::accessibleBy($user)->orderBy('name')->get(['id', 'name']);

        $blocksQuery = Block::accessibleBy($user)->when(
            $this->propertyId !== 'all',
            fn ($q) => $q->where('property_id', $this->propertyId)
        );
        $blocks = $blocksQuery->orderBy('name')->get(['id', 'name', 'property_id']);

        $tenancyIds = RentCharge::outstanding()->distinct()->pluck('tenancy_id');

        $query = Tenancy::whereIn('id', $tenancyIds)
            ->accessibleBy($user)
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
            ->with([
                'tenant',
                'house.block.property',
                'rentCharges' => fn ($q) => $q->outstanding()->orderBy('due_date'),
            ]);

        $tenancies = $query->get()->map(function (Tenancy $tenancy) {
            $outstanding = $tenancy->rentCharges;
            $grossArrears = $outstanding->sum(fn (RentCharge $c) => $c->amount_expected - $c->amount_paid);

            // A surplus sitting on a different (already-'advance') charge
            // for this same tenancy is real money already handed over —
            // showing it as arrears here would overstate what's actually
            // owed, the same inconsistency applyLateFees() guards against.
            $netArrears = max(0, $grossArrears - $tenancy->advanceCreditAvailable());

            return (object) [
                'tenancy' => $tenancy,
                'arrears' => $netArrears,
                'lateFeeTotal' => $outstanding->sum('late_fee_amount'),
                'oldestDueDate' => $outstanding->first()?->due_date,
                'daysOverdue' => $outstanding->first()?->due_date?->isPast()
                    ? (int) now()->diffInDays($outstanding->first()->due_date, absolute: true)
                    : 0,
            ];
        })->sortByDesc('arrears')->values();

        if ($this->minDaysOverdue !== 'all') {
            $tenancies = $tenancies->filter(fn ($row) => $row->daysOverdue >= (int) $this->minDaysOverdue)->values();
        }

        $page = $this->getPage();
        $rows = new LengthAwarePaginator(
            $tenancies->forPage($page, $this->perPage)->values(),
            $tenancies->count(),
            $this->perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('livewire.arrears.index', [
            'rows' => $rows,
            'properties' => $properties,
            'blocks' => $blocks,
            'totalArrears' => $tenancies->sum('arrears'),
            'pendingWaiverRequests' => $pendingWaiverRequests,
        ]);
    }
}
