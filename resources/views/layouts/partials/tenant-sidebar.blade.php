@php
    $tenant = auth()->user()->tenant;
    // Once a tenancy has formally ended, it's no longer the tenant's
    // "current home" — paying rent, viewing the deposit, or filing a new
    // maintenance request against a unit they no longer occupy doesn't
    // make sense. Dashboard/My Tenancy (which already shows past
    // tenancies)/Notifications/Profile stay reachable either way.
    $hasActiveTenancy = (bool) $tenant?->tenancies()->active()->exists();
    // Same fix as layouts.partials.sidebar: this partial is @include'd twice
    // (desktop column + mobile slide-out overlay). Both copies used to carry
    // "hidden lg:flex", so the mobile copy stayed display:none even while
    // its overlay was open.
    $mobile ??= false;

    $pendingActions = app(\App\Services\PendingActionsService::class);
    $rentActionCount = $tenant ? $pendingActions->tenantOverdueChargesCount($tenant) : 0;
    $depositActionCount = $tenant ? $pendingActions->tenantDepositOutstandingCount($tenant) : 0;
    $requestsActionCount = $tenant ? $pendingActions->tenantAwaitingConfirmationCount($tenant) : 0;
    $tenantNotificationsActionCount = $tenant ? $pendingActions->tenantUnreadNotificationsCount($tenant) : 0;
    $tenancyNoticeActionCount = $tenant ? $pendingActions->tenantTenancyNoticeActionCount($tenant)
        + $pendingActions->tenantRenewalRequestActionCount($tenant) : 0;
    $contractActionCount = $tenant ? $pendingActions->tenantContractAwaitingSignatureCount($tenant) : 0;
@endphp

@persist($persistKey ?? 'tenant-sidebar')
<aside
    x-data="{
        collapsed: {{ $mobile ? 'false' : "localStorage.getItem('tenant-sidebar-collapsed') === 'true'" }},
        activePath: window.location.pathname,
        isActive(path) { return this.activePath === path },
    }"
    x-init="
        @if (! $mobile)
        $watch('collapsed', value => localStorage.setItem('tenant-sidebar-collapsed', value));
        @endif
        document.addEventListener('livewire:navigated', () => { activePath = window.location.pathname });
    "
    @if (! $mobile)
    :class="collapsed ? 'w-[68px]' : 'w-64'"
    @endif
    class="{{ $mobile ? 'flex w-64 h-full' : 'hidden lg:flex min-h-screen sticky top-0' }} flex-col shrink-0 bg-navy text-white transition-all duration-200"
>
    <div class="h-16 flex items-center gap-2 px-4 border-b border-white/10">
        <div class="h-9 w-9 rounded-md bg-gradient-to-br from-navy-light/20 to-white/5 text-gold ring-1 ring-white/15 flex items-center justify-center font-semibold shrink-0 shadow-sm">K</div>
        <span x-show="!collapsed" x-cloak class="font-semibold tracking-wide">Kats Agencies</span>
    </div>

    <nav
        class="flex-1 overflow-y-auto py-4 space-y-6"
        x-data
        x-init="
            $el.scrollTop = sessionStorage.getItem('tenant-sidebar-scroll') || 0;
            $el.addEventListener('scroll', () => sessionStorage.setItem('tenant-sidebar-scroll', $el.scrollTop));
        "
    >
        <div>
            <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Overview</p>
            <a href="{{ route('tenant.dashboard') }}" wire:navigate
               :class="isActive('{{ route('tenant.dashboard', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
               class="flex items-center gap-3 px-4 py-2 text-sm">
                <x-nav-icon name="dashboard" class="h-5 w-5 shrink-0" />
                <span x-show="!collapsed" x-cloak>Dashboard</span>
            </a>
        </div>

        <x-nav-group label="My Tenancy" slug="tenant-my-tenancy" :active="request()->routeIs('tenant.rent', 'tenant.deposit', 'tenant.pay', 'tenant.payments', 'tenant.statements', 'tenant.tenancy', 'tenant.contract')">
            @if ($hasActiveTenancy)
                <a href="{{ route('tenant.rent') }}" wire:navigate
                   :class="isActive('{{ route('tenant.rent', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="rent" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">My Rent</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$rentActionCount" /></span>
                </a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('tenant.deposit'))
                {{-- Stays reachable even after move-out — this is exactly
                     where a former tenant tracks their deposit refund. --}}
                <a href="{{ route('tenant.deposit') }}" wire:navigate
                   :class="isActive('{{ route('tenant.deposit', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="deposits" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">My Deposit</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$depositActionCount" /></span>
                </a>
            @endif
            @if ($hasActiveTenancy)
                <a href="{{ route('tenant.pay') }}" wire:navigate
                   :class="isActive('{{ route('tenant.pay', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Pay Rent</span>
                </a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('tenant.payments'))
                <a href="{{ route('tenant.payments') }}" wire:navigate
                   :class="isActive('{{ route('tenant.payments', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Payments</span>
                </a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('tenant.statements'))
                <a href="{{ route('tenant.statements') }}" wire:navigate
                   :class="isActive('{{ route('tenant.statements', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Statements</span>
                </a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('tenant.tenancy'))
                <a href="{{ route('tenant.tenancy') }}" wire:navigate
                   :class="isActive('{{ route('tenant.tenancy', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="tenancies" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">My Tenancy</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$tenancyNoticeActionCount" /></span>
                </a>
            @endif
            @if ($hasActiveTenancy && \Illuminate\Support\Facades\Route::has('tenant.contract'))
                <a href="{{ route('tenant.contract') }}" wire:navigate
                   :class="isActive('{{ route('tenant.contract', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="documents" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Contract</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$contractActionCount" /></span>
                </a>
            @endif
        </x-nav-group>

        @if (\Illuminate\Support\Facades\Route::has('tenant.maintenance.create'))
            <x-nav-group label="Support" slug="tenant-support" :active="request()->routeIs('tenant.maintenance.*')">
                @if ($hasActiveTenancy)
                    <a href="{{ route('tenant.maintenance.create') }}" wire:navigate
                       :class="isActive('{{ route('tenant.maintenance.create', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                       class="flex items-center gap-3 px-4 py-2 text-sm">
                        <x-nav-icon name="maintenance" class="h-5 w-5 shrink-0" />
                        <span x-show="!collapsed" x-cloak>Maintenance &amp; Complaints</span>
                    </a>
                @endif
                <a href="{{ route('tenant.maintenance.index') }}" wire:navigate
                   :class="isActive('{{ route('tenant.maintenance.index', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="complaints" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">My Requests</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$requestsActionCount" /></span>
                </a>
            </x-nav-group>
        @endif

        <x-nav-group label="Communication" slug="tenant-communication" :active="request()->routeIs('tenant.notifications.*', 'tenant.announcements.*')">
            <a href="{{ route('tenant.notifications.index') }}" wire:navigate
               :class="isActive('{{ route('tenant.notifications.index', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
               class="flex items-center gap-3 px-4 py-2 text-sm">
                <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                <span x-show="!collapsed" x-cloak class="flex-1">Notifications</span>
                <span x-show="!collapsed" x-cloak><x-nav-badge :count="$tenantNotificationsActionCount" /></span>
            </a>
            @if (\Illuminate\Support\Facades\Route::has('tenant.announcements.index'))
                <a href="{{ route('tenant.announcements.index') }}" wire:navigate
                   :class="isActive('{{ route('tenant.announcements.index', absolute: false) }}') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5'"
                   class="flex items-center gap-3 px-4 py-2 text-sm">
                    <x-nav-icon name="audit" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Announcements</span>
                </a>
            @endif
        </x-nav-group>
    </nav>

    <div class="border-t border-white/10 p-3">
        @if (\Illuminate\Support\Facades\Route::has('tenant.profile'))
            <a href="{{ route('tenant.profile') }}" wire:navigate
               :class="isActive('{{ route('tenant.profile', absolute: false) }}') ? 'text-white' : 'text-white/60 hover:text-white'"
               class="flex items-center gap-2 px-2 py-2 text-sm">
                <x-nav-icon name="settings" class="h-4 w-4 shrink-0" />
                <span x-show="!collapsed" x-cloak>Profile</span>
            </a>
        @endif
        <button @click="collapsed = !collapsed" class="w-full flex items-center gap-2 px-2 py-2 text-white/60 hover:text-white text-sm">
            <x-nav-icon name="chevron-left" class="transition-transform" x-bind:class="{ 'rotate-180': collapsed }" />
            <span x-show="!collapsed" x-cloak>Collapse</span>
        </button>

        <div class="flex items-center gap-2 px-2 py-2 mt-1">
            <div class="h-8 w-8 rounded-full bg-white/10 flex items-center justify-center text-xs font-semibold shrink-0">
                {{ collect(explode(' ', $tenant?->name ?? auth()->user()->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
            </div>
            <div x-show="!collapsed" x-cloak class="min-w-0">
                <p class="text-sm font-medium text-white truncate">{{ $tenant?->name ?? auth()->user()->name }}</p>
                <p class="text-[11px] text-white/50">Tenant</p>
            </div>
        </div>
    </div>
</aside>
@endpersist
