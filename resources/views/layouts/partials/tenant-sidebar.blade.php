@php
    $tenant = auth()->user()->tenant;
@endphp

<aside
    x-data="{ collapsed: localStorage.getItem('tenant-sidebar-collapsed') === 'true' }"
    x-init="$watch('collapsed', value => localStorage.setItem('tenant-sidebar-collapsed', value))"
    :class="collapsed ? 'w-[68px]' : 'w-64'"
    class="hidden lg:flex flex-col shrink-0 bg-navy text-white transition-all duration-200 min-h-screen sticky top-0"
>
    <div class="h-16 flex items-center gap-2 px-4 border-b border-white/10">
        <div class="h-8 w-8 rounded-md bg-white/10 text-gold flex items-center justify-center font-semibold shrink-0">K</div>
        <span x-show="!collapsed" x-cloak class="font-semibold tracking-wide">Kats Agencies</span>
    </div>

    <nav class="flex-1 overflow-y-auto py-4 space-y-6">
        <div>
            <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Overview</p>
            <a href="{{ route('tenant.dashboard') }}" wire:navigate
               class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.dashboard') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                <x-nav-icon name="dashboard" class="h-5 w-5 shrink-0" />
                <span x-show="!collapsed" x-cloak>Dashboard</span>
            </a>
        </div>

        <div>
            <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">My Tenancy</p>
            <a href="{{ route('tenant.rent') }}" wire:navigate
               class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.rent') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                <x-nav-icon name="rent" class="h-5 w-5 shrink-0" />
                <span x-show="!collapsed" x-cloak>My Rent</span>
            </a>
            <a href="{{ route('tenant.pay') }}" wire:navigate
               class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.pay') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                <span x-show="!collapsed" x-cloak>Pay Rent</span>
            </a>
            @if (\Illuminate\Support\Facades\Route::has('tenant.payments'))
                <a href="{{ route('tenant.payments') }}" wire:navigate
                   class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.payments') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Payments</span>
                </a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('tenant.statements'))
                <a href="{{ route('tenant.statements') }}" wire:navigate
                   class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.statements') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Statements</span>
                </a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('tenant.tenancy'))
                <a href="{{ route('tenant.tenancy') }}" wire:navigate
                   class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.tenancy') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenancies" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>My Tenancy</span>
                </a>
            @endif
            @if (\Illuminate\Support\Facades\Route::has('tenant.contract'))
                <a href="{{ route('tenant.contract') }}" wire:navigate
                   class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.contract') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="documents" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Contract</span>
                </a>
            @endif
        </div>

        @if (\Illuminate\Support\Facades\Route::has('tenant.maintenance.create'))
            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Support</p>
                <a href="{{ route('tenant.maintenance.create') }}" wire:navigate
                   class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.maintenance.create') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="maintenance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Maintenance &amp; Complaints</span>
                </a>
                <a href="{{ route('tenant.maintenance.index') }}" wire:navigate
                   class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.maintenance.index') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="complaints" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>My Requests</span>
                </a>
            </div>
        @endif

        <div>
            <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Communication</p>
            <a href="{{ route('tenant.notifications.index') }}" wire:navigate
               class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.notifications.index') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                <span x-show="!collapsed" x-cloak>Notifications</span>
            </a>
            @if (\Illuminate\Support\Facades\Route::has('tenant.announcements.index'))
                <a href="{{ route('tenant.announcements.index') }}" wire:navigate
                   class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('tenant.announcements.index') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="audit" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Announcements</span>
                </a>
            @endif
        </div>
    </nav>

    <div class="border-t border-white/10 p-3">
        @if (\Illuminate\Support\Facades\Route::has('tenant.profile'))
            <a href="{{ route('tenant.profile') }}" wire:navigate class="flex items-center gap-2 px-2 py-2 text-sm {{ request()->routeIs('tenant.profile') ? 'text-white' : 'text-white/60 hover:text-white' }}">
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
