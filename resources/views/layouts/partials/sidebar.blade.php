@php
    $user = auth()->user();
    $isAdmin = $user->hasRole('admin');
@endphp

<aside
    x-data="{ collapsed: localStorage.getItem('sidebar-collapsed') === 'true' }"
    x-init="$watch('collapsed', value => localStorage.setItem('sidebar-collapsed', value))"
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
            <a href="{{ $isAdmin ? route('admin.dashboard') : route('landlord.dashboard') }}" wire:navigate
               class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.dashboard', 'landlord.dashboard') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                <x-nav-icon name="dashboard" class="h-5 w-5 shrink-0" />
                <span x-show="!collapsed" x-cloak>Dashboard</span>
            </a>
        </div>

        @if ($isAdmin)
            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Landlord Management</p>

                <a href="{{ route('admin.landlords.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.landlords.index', 'admin.landlords.show') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="landlords" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlords</span>
                </a>
                <a href="{{ route('admin.landlords.applications') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.landlords.applications') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="applications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlord Applications</span>
                </a>
                <a href="{{ route('admin.landlords.properties') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.landlords.properties') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="properties" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlord Properties</span>
                </a>
                <a href="{{ route('admin.landlords.activity') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.landlords.activity') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="audit" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlord Activity</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Platform Monitoring</p>

                <a href="{{ route('admin.platform.overview') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.platform.overview') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Platform Overview</span>
                </a>
                <a href="{{ route('admin.platform.occupancy') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.platform.occupancy') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="units" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Occupancy Overview</span>
                </a>
                <a href="{{ route('admin.audit-logs.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm text-white/70 hover:text-white hover:bg-white/5">
                    <x-nav-icon name="audit" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>System Activity</span>
                </a>
                <a href="{{ route('admin.notifications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.notifications.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Notifications / Alerts</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">System</p>

                <a href="{{ route('admin.users.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.users.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="users" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Users &amp; Roles</span>
                </a>
                <a href="{{ route('admin.permissions.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.permissions.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="settings" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Permissions</span>
                </a>
                <a href="{{ route('admin.settings.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.settings.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="settings" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>System Settings</span>
                </a>
                <a href="{{ route('admin.settings.index', ['tab' => 'notifications']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm text-white/70 hover:text-white hover:bg-white/5">
                    <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Notification Settings</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Reports</p>

                <a href="{{ route('admin.reports.platform') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.reports.platform') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Platform Reports</span>
                </a>
                <a href="{{ route('admin.reports.landlord') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.reports.landlord') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlord Reports</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Security</p>

                <a href="{{ route('admin.audit-logs.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.audit-logs.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="audit" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Audit Logs</span>
                </a>
            </div>
        @else
            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">My Properties</p>

                <a href="{{ route('manage.properties.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.properties.*', 'manage.blocks.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="properties" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Properties</span>
                </a>
                <a href="{{ route('manage.units.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.units.*', 'manage.houses.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="units" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Units</span>
                </a>
                <a href="{{ route('manage.occupancy.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.occupancy.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Occupancy</span>
                </a>
                <a href="{{ route('manage.vacancies.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.vacancies.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="units" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Vacancies</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Tenancy</p>

                <a href="{{ route('manage.tenants.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.tenants.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenants" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Tenants</span>
                </a>
                <a href="{{ route('manage.tenancies.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.tenancies.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenancies" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Tenancies &amp; Contracts</span>
                </a>
                <a href="{{ route('manage.applications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.applications.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="applications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Applications</span>
                </a>
                <a href="{{ route('manage.tenant-invitations.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.tenant-invitations.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenants" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Tenant Invitations</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Rent &amp; Finance</p>

                <a href="{{ route('manage.rent-charges.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.rent-charges.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="rent" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Rent &amp; Payments</span>
                </a>
                <a href="{{ route('manage.arrears.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.arrears.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="arrears" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Arrears</span>
                </a>
                <a href="{{ route('manage.deposits.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.deposits.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="deposits" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Deposits</span>
                </a>
                <a href="{{ route('manage.expenses.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.expenses.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="expenses" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Expenses</span>
                </a>
                <a href="{{ route('manage.income.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.income.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Income</span>
                </a>
                <a href="{{ route('manage.finance.overview') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.finance.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Financial Overview</span>
                </a>
                <a href="{{ route('manage.reports.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.reports.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Reports</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Maintenance</p>

                <a href="{{ route('manage.maintenance.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.maintenance.index') && request('tab', 'dashboard') === 'dashboard' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="maintenance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Maintenance Dashboard</span>
                </a>
                <a href="{{ route('manage.maintenance.index', ['tab' => 'requests']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('tab') === 'requests' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="complaints" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Requests</span>
                </a>
                <a href="{{ route('manage.maintenance.index', ['tab' => 'scheduled']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('tab') === 'scheduled' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenancies" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Scheduled Maintenance</span>
                </a>
                <a href="{{ route('manage.maintenance.index', ['tab' => 'vendors']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('tab') === 'vendors' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="users" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Vendors</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Communication</p>

                <a href="{{ route('manage.notifications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.notifications.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Notifications</span>
                </a>
                <a href="{{ route('manage.messages.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.messages.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="complaints" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Tenant Messages</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Documents</p>

                <a href="{{ route('manage.documents.index', ['category' => 'lease_agreement']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.documents.*') && request('category') === 'lease_agreement' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="documents" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Contracts</span>
                </a>
                <a href="{{ route('manage.documents.index', ['category' => 'property_document']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('category') === 'property_document' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="properties" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Property Documents</span>
                </a>
                <a href="{{ route('manage.documents.index', ['category' => 'receipt']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('category') === 'receipt' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="expenses" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Receipts &amp; Invoices</span>
                </a>
            </div>

            <div>
                <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Analytics</p>

                <a href="{{ route('manage.analytics.performance') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.analytics.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Property Performance</span>
                </a>
                <a href="{{ route('manage.analytics.performance') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm text-white/70 hover:text-white hover:bg-white/5">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>ROI &amp; Returns</span>
                </a>
                <a href="{{ route('manage.finance.overview') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm text-white/70 hover:text-white hover:bg-white/5">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Income vs Expenses</span>
                </a>
            </div>
        @endif
    </nav>

    <div class="border-t border-white/10 p-3">
        @unless ($isAdmin)
            <a href="{{ route('manage.settings.index') }}" wire:navigate class="flex items-center gap-2 px-2 py-2 text-sm {{ request()->routeIs('manage.settings.*') ? 'text-white' : 'text-white/60 hover:text-white' }}">
                <x-nav-icon name="settings" class="h-4 w-4 shrink-0" />
                <span x-show="!collapsed" x-cloak>Settings</span>
            </a>
        @endunless
        <button @click="collapsed = !collapsed" class="w-full flex items-center gap-2 px-2 py-2 text-white/60 hover:text-white text-sm">
            <x-nav-icon name="chevron-left" class="transition-transform" x-bind:class="{ 'rotate-180': collapsed }" />
            <span x-show="!collapsed" x-cloak>Collapse</span>
        </button>

        <div class="flex items-center gap-2 px-2 py-2 mt-1">
            <div class="h-8 w-8 rounded-full bg-white/10 flex items-center justify-center text-xs font-semibold shrink-0">
                {{ collect(explode(' ', $user->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
            </div>
            <div x-show="!collapsed" x-cloak class="min-w-0">
                <p class="text-sm font-medium text-white truncate">{{ $user->name }}</p>
                <p class="text-[11px] text-white/50">{{ $isAdmin ? 'System Administrator' : 'Landlord' }}</p>
            </div>
        </div>
    </div>
</aside>
