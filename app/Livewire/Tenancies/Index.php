<?php

namespace App\Livewire\Tenancies;

use App\Models\Block;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Services\ContractService;
use App\Services\MpesaService;
use App\Services\NotificationService;
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
    public string $status = 'active';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public string $contractFilter = 'all';

    #[Url]
    public int $perPage = 25;

    public ?int $renewingTenancyId = null;

    public string $renew_lease_end_date = '';

    public ?string $generatedInviteLink = null;

    public ?string $generatedInviteFor = null;

    public ?string $generatedInviteWhatsappUrl = null;

    /** @var array<int, array{name: string, link: string, whatsappUrl: ?string}> */
    public array $bulkInvites = [];

    public bool $showBulkContracts = false;

    public bool $includeAlreadySigned = false;

    public string $bulkContractPropertyId = 'all';

    /** @var int[] */
    public array $selectedBulkContractTenancyIds = [];

    /** @var array<int, array{name: string, result: string}> */
    public array $bulkContractResults = [];

    // TenancyPolicy::viewAny() passes for any manager — the landlord-granted
    // tenancies.view permission is the real gate, same as Contracts/Arrears.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasManagerPermission('tenancies.view'), 403);
    }

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->resetPage();
    }

    public function openRenew(int $tenancyId): void
    {
        $tenancy = Tenancy::findOrFail($tenancyId);
        $this->authorize('update', $tenancy);

        $this->renewingTenancyId = $tenancyId;
        $this->renew_lease_end_date = $tenancy->lease_end_date?->addYear()->format('Y-m-d')
            ?? now()->addYear()->format('Y-m-d');
    }

    public function cancelRenew(): void
    {
        $this->reset(['renewingTenancyId', 'renew_lease_end_date']);
    }

    public function sendPortalInvite(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $tenancy = Tenancy::accessibleBy(Auth::user())->where('tenant_id', $tenantId)
            ->with('house.block.property')
            ->firstOrFail();
        $this->authorize('update', $tenancy);

        if ($tenant->hasPortalAccess()) {
            return;
        }

        // Regenerated every time — only the most recently shared link works,
        // so an old link that leaked (or was sent to the wrong person) stops
        // working the moment a fresh one is sent.
        $tenant->update([
            'portal_invite_token' => \Illuminate\Support\Str::random(48),
            'portal_invite_sent_at' => now(),
        ]);

        $this->generatedInviteLink = route('tenant-portal-invite.accept', $tenant->portal_invite_token);
        $this->generatedInviteFor = $tenant->name;
        $this->generatedInviteWhatsappUrl = $this->buildWhatsappUrl($tenant, $this->generatedInviteLink, $tenancy);
    }

    public function dismissInviteLink(): void
    {
        $this->reset(['generatedInviteLink', 'generatedInviteFor', 'generatedInviteWhatsappUrl']);
    }

    /**
     * Generates a fresh invite link for every accessible tenant who's never
     * been invited yet. WhatsApp's click-to-chat links can't send to more
     * than one number at once — no API is wired up for that — so this just
     * removes the need to open each tenancy/row individually; the landlord
     * still taps "Send via WhatsApp" once per tenant below.
     */
    public function sendInviteToAll(): void
    {
        $user = Auth::user();

        abort_unless($user->hasRole('landlord') || $user->hasManagerPermission('tenancies.terminate'), 403);

        $tenancies = Tenancy::accessibleBy($user)
            ->active()
            ->whereHas('tenant', fn ($q) => $q->whereNull('user_id')->whereNull('portal_invite_token'))
            ->with(['tenant', 'house.block.property'])
            ->get()
            ->unique('tenant_id');

        $this->bulkInvites = $tenancies->map(function (Tenancy $tenancy) {
            $tenant = $tenancy->tenant;

            $tenant->update([
                'portal_invite_token' => \Illuminate\Support\Str::random(48),
                'portal_invite_sent_at' => now(),
            ]);

            $link = route('tenant-portal-invite.accept', $tenant->portal_invite_token);

            return [
                'name' => $tenant->name,
                'link' => $link,
                'whatsappUrl' => $this->buildWhatsappUrl($tenant, $link, $tenancy),
            ];
        })->values()->all();

        if (empty($this->bulkInvites)) {
            session()->flash('status', 'Everyone already has portal access or a pending invite — nothing new to send.');
        }
    }

    public function dismissBulkInvites(): void
    {
        $this->bulkInvites = [];
    }

    /**
     * Eligible tenancies for the bulk-send review list: active tenancies
     * without a currently-valid signed contract, unless the landlord
     * explicitly opts to include (and so re-issue a revision to)
     * already-signed ones. Optionally narrowed to one property at a time —
     * different properties can carry different agreements (see
     * ContractTemplate::resolveDefaultFor()), so reviewing/sending one
     * property's batch at a time keeps the review list matched to a single
     * template instead of several mixed together.
     */
    private function bulkContractEligibleTenancies()
    {
        $user = Auth::user();

        return Tenancy::accessibleBy($user)
            ->active()
            ->when($this->bulkContractPropertyId !== 'all', fn ($q) => $q->whereHas(
                'house.block',
                fn ($q2) => $q2->where('property_id', $this->bulkContractPropertyId)
            ))
            ->with(['tenant', 'house.block.property', 'contract'])
            ->get()
            ->filter(fn (Tenancy $t) => $this->includeAlreadySigned || $t->contract?->status !== 'signed')
            ->values();
    }

    public function openBulkContracts(): void
    {
        abort_unless(Auth::user()->hasRole('landlord') || Auth::user()->hasManagerPermission('tenancies.manage_contracts'), 403);

        $this->bulkContractResults = [];
        $this->bulkContractPropertyId = 'all';
        $this->selectedBulkContractTenancyIds = $this->bulkContractEligibleTenancies()->pluck('id')->all();
        $this->showBulkContracts = true;
    }

    public function updatedIncludeAlreadySigned(): void
    {
        $this->selectedBulkContractTenancyIds = $this->bulkContractEligibleTenancies()->pluck('id')->all();
    }

    public function updatedBulkContractPropertyId(): void
    {
        $this->selectedBulkContractTenancyIds = $this->bulkContractEligibleTenancies()->pluck('id')->all();
    }

    public function dismissBulkContracts(): void
    {
        $this->reset(['showBulkContracts', 'includeAlreadySigned', 'bulkContractPropertyId', 'selectedBulkContractTenancyIds', 'bulkContractResults']);
    }

    /**
     * Generates (or revises) and sends a contract for every checked
     * tenancy, one at a time, so a missing template or a single bad record
     * doesn't abort the whole batch — each tenancy's own outcome is
     * reported back, and every contract issued this way still goes through
     * ContractService, so it gets the same version history / superseded
     * trail as a one-off "Generate" from the Tenancy page would.
     */
    public function sendBulkContracts(): void
    {
        $user = Auth::user();
        abort_unless($user->hasRole('landlord') || $user->hasManagerPermission('tenancies.manage_contracts'), 403);

        $tenancies = Tenancy::whereIn('id', $this->selectedBulkContractTenancyIds)
            ->accessibleBy($user)
            ->with(['tenant', 'house.block.property.landlord'])
            ->get();

        $contractService = app(ContractService::class);
        $notificationService = app(NotificationService::class);
        $results = [];

        foreach ($tenancies as $tenancy) {
            try {
                $contract = $contractService->generateForTenancy($tenancy);
                $contractService->markSent($contract);

                $notificationService->notify(
                    $tenancy->tenant,
                    'contract_sent',
                    'Your Tenancy Agreement Is Ready to Sign',
                    'Your landlord has sent your tenancy agreement. Please review and sign it from your tenant portal.',
                    ['in_app', 'email'],
                    $tenancy,
                );

                $results[] = ['name' => $tenancy->tenant->name, 'result' => 'Sent for signature'];
            } catch (\RuntimeException $e) {
                $results[] = ['name' => $tenancy->tenant->name, 'result' => 'Skipped — no default contract template configured'];
            }
        }

        $this->bulkContractResults = $results;
        $this->selectedBulkContractTenancyIds = [];
        session()->flash('status', count($results).' contract(s) processed.');
    }

    private function buildWhatsappUrl(Tenant $tenant, string $link, ?Tenancy $tenancy = null): ?string
    {
        if (! $tenant->phone) {
            return null;
        }

        $unit = $tenancy?->house?->block?->property
            ? "{$tenancy->house->block->property->name}, {$tenancy->house->block->name}, Unit {$tenancy->house->house_number}"
            : 'your rented unit';

        // WhatsApp's own formatting syntax, not standard Markdown: single
        // asterisks for *bold*, single underscores for _italic_ — doubling
        // them (as in "**bold**") just prints the literal asterisks.
        $message = <<<MESSAGE
        Hello {$tenant->name},

        Your property management has partnered with Kats Agencies to provide a tenant portal for your unit at *{$unit}*.

        Please create your *free tenant account* using the link below. The portal will allow you to:

        - Check your rent balance
        - View your payment history
        - Pay rent online

        *Create your account here:* {$link}

        Thank you for your cooperation.

        _Kats Agencies_
        MESSAGE;

        return 'https://wa.me/'.app(MpesaService::class)->normalizePhone($tenant->phone).'?text='.rawurlencode($message);
    }

    public function renew(): void
    {
        $tenancy = Tenancy::findOrFail($this->renewingTenancyId);
        $this->authorize('update', $tenancy);

        $this->validate([
            'renew_lease_end_date' => ['required', 'date', 'after:today'],
        ]);

        $tenancy->update([
            'lease_end_date' => $this->renew_lease_end_date,
            'status' => 'active',
        ]);

        // Renewing the lease date alone used to leave the agreement itself
        // untouched — generating a fresh version here keeps the contract
        // history consistent with what the tenancy actually says. A missing
        // default template still renews the dates; the landlord can
        // generate/upload the agreement manually afterward.
        try {
            app(ContractService::class)->generateForTenancy($tenancy);
        } catch (\RuntimeException $e) {
            // No default contract template configured — handled below.
        }

        $this->cancelRenew();
        session()->flash('status', 'Tenancy renewed.');
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

        $query = Tenancy::with(['tenant', 'house.block.property'])
            ->when($this->status === 'active', fn ($q) => $q->whereIn('status', Tenancy::OCCUPYING_STATUSES))
            ->when($this->status === 'archived', fn ($q) => $q->whereNotIn('status', Tenancy::OCCUPYING_STATUSES)->where('status', '!=', 'pending'))
            ->when(! in_array($this->status, ['all', 'active', 'archived'], true), fn ($q) => $q->where('status', $this->status))
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
            ->when($this->contractFilter === 'needs_contract', fn ($q) => $q->whereDoesntHave(
                'contract',
                fn ($q2) => $q2->where('status', 'signed')
            ))
            ->latest()
            ->accessibleBy($user);

        $expiringSoonQuery = Tenancy::expiringSoon(30)->with(['tenant', 'house.block.property'])->accessibleBy($user);

        return view('livewire.tenancies.index', [
            'tenancies' => $query->paginate($this->perPage),
            'properties' => $properties,
            'blocks' => $blocks,
            'expiringSoon' => $expiringSoonQuery->orderBy('lease_end_date')->get(),
            'bulkContractCandidates' => $this->showBulkContracts ? $this->bulkContractEligibleTenancies() : collect(),
        ]);
    }
}
