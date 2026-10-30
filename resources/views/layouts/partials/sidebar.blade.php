@php
    $user = auth()->user();
    $isAdmin = $user->hasRole('admin');
    $isManager = $user->hasRole('manager');
    $pendingLandlordApplications = $isAdmin
        ? \App\Models\User::role('landlord')->where('status', 'inactive')->whereNull('approved_at')->count()
        : 0;

    $pendingActions = app(\App\Services\PendingActionsService::class);
    $adminUnreadNotifications = $isAdmin ? $pendingActions->adminUnreadNotificationsCount($user) : 0;
    $openSupportTicketsCount = $isAdmin ? $pendingActions->openSupportTicketsCount() : 0;
    $maintenanceActionCount = ! $isAdmin ? $pendingActions->maintenanceCount($user) : 0;
    $depositsActionCount = ! $isAdmin && ! $isManager ? $pendingActions->depositsOutstandingCount($user) : 0;
    $arrearsActionCount = ! $isAdmin ? $pendingActions->arrearsCount($user)
        + $pendingActions->lateFeeWaiverRequestsAwaitingDecisionCount($user)
        + $pendingActions->paymentsAwaitingConfirmationCount($user) : 0;
    $notificationsActionCount = ! $isAdmin ? $pendingActions->unreadNotificationsCount($user) : 0;
    $payoutsActionCount = $pendingActions->payoutsAwaitingLandlordConfirmationCount($user);
    $disbursementsActionCount = $isAdmin ? $pendingActions->disbursementsNeedingAttentionCount() : 0;
    $noticesActionCount = ! $isAdmin ? $pendingActions->tenancyNoticesAwaitingDecisionCount($user)
        + $pendingActions->tenancyChangeRequestsAwaitingDecisionCount($user)
        + $pendingActions->tenancyRenewalRequestsAwaitingDecisionCount($user)
        + $pendingActions->dueDayChangeRequestsAwaitingDecisionCount($user) : 0;
    // This partial is @include'd twice from layouts.admin: once as the
    // always-rendered desktop column (hidden below lg, shown at lg+), and
    // once inside the mobile slide-out overlay (which is itself hidden
    // below lg via its own wrapper). Without this flag both copies carried
    // the same "hidden lg:flex" class, so the mobile copy stayed
    // display:none even while its overlay was open — a backdrop with no
    // nav content on it.
    $mobile ??= false;
@endphp

<aside
    x-data="{ collapsed: {{ $mobile ? 'false' : "localStorage.getItem('sidebar-collapsed') === 'true'" }} }"
    @if (! $mobile)
    x-init="$watch('collapsed', value => localStorage.setItem('sidebar-collapsed', value))"
    :class="collapsed ? 'w-[68px]' : 'w-64'"
    @endif
    class="{{ $mobile ? 'flex w-64 h-full' : 'hidden lg:flex min-h-screen sticky top-0 w-64' }} flex-col shrink-0 bg-navy text-white transition-all duration-200"
>
    <div class="h-16 flex items-center gap-2 px-4 border-b border-white/10">
        <div class="h-9 w-9 rounded-md bg-gradient-to-br from-navy-light/20 to-white/5 text-gold ring-1 ring-white/15 flex items-center justify-center font-semibold shrink-0 shadow-sm">K</div>
        <span x-show="!collapsed" x-cloak class="font-semibold tracking-wide">Kats Agencies</span>
    </div>

    <nav class="flex-1 overflow-y-auto py-4 space-y-6">
        <div>
            <p x-show="!collapsed" x-cloak class="px-4 text-[11px] font-semibold text-white/40 uppercase tracking-wider mb-1">Overview</p>
            <a href="{{ $isAdmin ? route('admin.dashboard') : ($isManager ? route('manager.dashboard') : route('landlord.dashboard')) }}" wire:navigate
               class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.dashboard', 'landlord.dashboard', 'manager.dashboard') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                <x-nav-icon name="dashboard" class="h-5 w-5 shrink-0" />
                <span x-show="!collapsed" x-cloak>Dashboard</span>
            </a>
        </div>

        @if ($isAdmin)
            <x-nav-group label="Landlord Management" slug="admin-landlords" :active="request()->routeIs('admin.landlords.*')">
                <a href="{{ route('admin.landlords.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.landlords.index', 'admin.landlords.show') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="landlords" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlords</span>
                </a>
                <a href="{{ route('admin.landlords.applications') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.landlords.applications') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="applications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Landlord Applications</span>
                    @if ($pendingLandlordApplications > 0)
                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-danger text-white text-[10px] font-semibold">
                            {{ $pendingLandlordApplications > 9 ? '9+' : $pendingLandlordApplications }}
                        </span>
                    @endif
                </a>
                <a href="{{ route('admin.landlords.properties') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.landlords.properties') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="properties" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlord Properties</span>
                </a>
                <a href="{{ route('admin.landlords.activity') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.landlords.activity') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="audit" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlord Activity</span>
                </a>
            </x-nav-group>

            <x-nav-group label="Platform Monitoring" slug="admin-platform" :active="request()->routeIs('admin.platform.*', 'admin.notifications.*', 'admin.support-tickets.*')">
                <a href="{{ route('admin.platform.overview') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.platform.overview') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Platform Overview</span>
                </a>
                <a href="{{ route('admin.platform.occupancy') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.platform.occupancy') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="units" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Occupancy Overview</span>
                </a>
                <a href="{{ route('admin.notifications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.notifications.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Notifications / Alerts</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$adminUnreadNotifications" /></span>
                </a>
                <a href="{{ route('admin.support-tickets.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.support-tickets.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Support Tickets</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$openSupportTicketsCount" /></span>
                </a>
            </x-nav-group>

            <x-nav-group label="System" slug="admin-system" :active="request()->routeIs('admin.users.*', 'admin.permissions.*', 'admin.settings.*')">
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
            </x-nav-group>

            <x-nav-group label="Reports" slug="admin-reports" :active="request()->routeIs('admin.reports.*', 'admin.disbursements.*')">
                <a href="{{ route('admin.reports.platform') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.reports.platform') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Platform Reports</span>
                </a>
                <a href="{{ route('admin.reports.landlord') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.reports.landlord') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Landlord Reports</span>
                </a>
                <a href="{{ route('admin.disbursements.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.disbursements.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Disbursements</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$disbursementsActionCount" /></span>
                </a>
            </x-nav-group>

            <x-nav-group label="Security" slug="admin-security" :active="request()->routeIs('admin.audit-logs.*')">
                <a href="{{ route('admin.audit-logs.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('admin.audit-logs.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="audit" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Audit Logs</span>
                </a>
            </x-nav-group>
        @elseif ($isManager)
            <x-nav-group label="Property Management" slug="manager-properties" :active="request()->routeIs('manage.properties.*', 'manage.blocks.*', 'manage.units.*', 'manage.houses.*')">
                <a href="{{ route('manage.properties.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.properties.*', 'manage.blocks.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="properties" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>My Properties</span>
                </a>
                <a href="{{ route('manage.units.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.units.*', 'manage.houses.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="units" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Units</span>
                </a>
            </x-nav-group>

            @if ($user->hasManagerPermission('tenants.view'))
                <x-nav-group label="Tenants" slug="manager-tenants" :active="request()->routeIs('manage.tenants.*')">
                    <a href="{{ route('manage.tenants.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.tenants.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                        <x-nav-icon name="tenants" class="h-5 w-5 shrink-0" />
                        <span x-show="!collapsed" x-cloak>Tenants</span>
                    </a>
                </x-nav-group>
            @endif

            @if ($user->hasManagerPermission('tenancies.view'))
                <x-nav-group label="Tenancies" slug="manager-tenancies" :active="request()->routeIs('manage.tenancies.*', 'manage.contracts.*')">
                    <a href="{{ route('manage.tenancies.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.tenancies.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                        <x-nav-icon name="tenancies" class="h-5 w-5 shrink-0" />
                        <span x-show="!collapsed" x-cloak class="flex-1">Tenancies &amp; Contracts</span>
                        <span x-show="!collapsed" x-cloak><x-nav-badge :count="$noticesActionCount" /></span>
                    </a>
                    <a href="{{ route('manage.contracts.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.contracts.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                        <x-nav-icon name="documents" class="h-5 w-5 shrink-0" />
                        <span x-show="!collapsed" x-cloak>Contracts</span>
                    </a>
                </x-nav-group>
            @endif

            @if ($user->hasManagerPermission('applications.view'))
                <x-nav-group label="Applications" slug="manager-applications" :active="request()->routeIs('manage.applications.*')">
                    <a href="{{ route('manage.applications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.applications.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                        <x-nav-icon name="applications" class="h-5 w-5 shrink-0" />
                        <span x-show="!collapsed" x-cloak>Applications</span>
                    </a>
                </x-nav-group>
            @endif

            @if ($user->hasManagerPermission('maintenance.view') || $user->hasManagerPermission('complaints.view'))
                <x-nav-group label="Operations" slug="manager-operations" :active="request()->routeIs('manage.maintenance.*')">
                    @if ($user->hasManagerPermission('maintenance.view'))
                        <a href="{{ route('manage.maintenance.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.maintenance.index') && request('tab', 'dashboard') === 'dashboard' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                            <x-nav-icon name="maintenance" class="h-5 w-5 shrink-0" />
                            <span x-show="!collapsed" x-cloak class="flex-1">Maintenance</span>
                            <span x-show="!collapsed" x-cloak><x-nav-badge :count="$maintenanceActionCount" /></span>
                        </a>
                        <a href="{{ route('manage.maintenance.schedules.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.maintenance.schedules.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                            <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                            <span x-show="!collapsed" x-cloak>Preventive Schedules</span>
                        </a>
                    @endif
                    @if ($user->hasManagerPermission('complaints.view'))
                        <a href="{{ route('manage.maintenance.index', ['tab' => 'requests']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('tab') === 'requests' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                            <x-nav-icon name="complaints" class="h-5 w-5 shrink-0" />
                            <span x-show="!collapsed" x-cloak>Complaints</span>
                        </a>
                    @endif
                </x-nav-group>
            @endif

            <x-nav-group label="Communication" slug="manager-communication" :active="request()->routeIs('manage.notifications.*', 'manage.documents.*')">
                <a href="{{ route('manage.notifications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.notifications.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Notifications</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$notificationsActionCount" /></span>
                </a>
                <a href="{{ route('manage.documents.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.documents.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="documents" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Documents</span>
                </a>
            </x-nav-group>

            @if ($user->hasManagerPermission('finance.view_rent_status'))
                <x-nav-group label="Rent" slug="manager-rent" :active="request()->routeIs('manage.rent-charges.*', 'manage.arrears.*')">
                    <a href="{{ route('manage.rent-charges.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.rent-charges.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                        <x-nav-icon name="rent" class="h-5 w-5 shrink-0" />
                        <span x-show="!collapsed" x-cloak>Rent Status</span>
                    </a>
                    <a href="{{ route('manage.arrears.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.arrears.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                        <x-nav-icon name="arrears" class="h-5 w-5 shrink-0" />
                        <span x-show="!collapsed" x-cloak class="flex-1">Arrears</span>
                        <span x-show="!collapsed" x-cloak><x-nav-badge :count="$arrearsActionCount" /></span>
                    </a>
                </x-nav-group>
            @endif
        @else
            <x-nav-group label="My Properties" slug="landlord-properties" :active="request()->routeIs('manage.properties.*', 'manage.blocks.*', 'manage.units.*', 'manage.houses.*', 'manage.occupancy.*', 'manage.vacancies.*')">
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
            </x-nav-group>

            <x-nav-group label="Team" slug="landlord-team" :active="request()->routeIs('manage.managers.*')">
                <a href="{{ route('manage.managers.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.managers.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="users" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Managers / Agents</span>
                </a>
            </x-nav-group>

            <x-nav-group label="Tenancy" slug="landlord-tenancy" :active="request()->routeIs('manage.tenants.*', 'manage.tenancies.*', 'manage.contracts.*', 'manage.applications.*', 'manage.tenant-invitations.*')">
                <a href="{{ route('manage.tenants.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.tenants.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenants" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Tenants</span>
                </a>
                <a href="{{ route('manage.tenancies.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.tenancies.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenancies" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Tenancies &amp; Contracts</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$noticesActionCount" /></span>
                </a>
                <a href="{{ route('manage.contracts.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.contracts.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="documents" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Contracts</span>
                </a>
                <a href="{{ route('manage.applications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.applications.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="applications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Applications</span>
                </a>
                <a href="{{ route('manage.tenant-invitations.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.tenant-invitations.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenants" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Tenant Invitations</span>
                </a>
            </x-nav-group>

            <x-nav-group label="Rent & Finance" slug="landlord-finance" :active="request()->routeIs('manage.rent-charges.*', 'manage.arrears.*', 'manage.deposits.*', 'manage.expenses.*', 'manage.income.*', 'manage.finance.*', 'landlord.payouts.*', 'manage.reports.*')">
                <a href="{{ route('manage.rent-charges.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.rent-charges.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="rent" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Rent &amp; Payments</span>
                </a>
                <a href="{{ route('manage.arrears.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.arrears.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="arrears" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Arrears</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$arrearsActionCount" /></span>
                </a>
                <a href="{{ route('manage.deposits.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.deposits.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="deposits" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Deposits</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$depositsActionCount" /></span>
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
                <a href="{{ route('landlord.payouts.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('landlord.payouts.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="finance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">My Payouts</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$payoutsActionCount" /></span>
                </a>
                <a href="{{ route('manage.reports.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.reports.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Reports</span>
                </a>
            </x-nav-group>

            <x-nav-group label="Maintenance" slug="landlord-maintenance" :active="request()->routeIs('manage.maintenance.*')">
                <a href="{{ route('manage.maintenance.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.maintenance.index') && request('tab', 'dashboard') === 'dashboard' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="maintenance" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Maintenance Dashboard</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$maintenanceActionCount" /></span>
                </a>
                <a href="{{ route('manage.maintenance.index', ['tab' => 'requests']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('tab') === 'requests' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="complaints" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Requests</span>
                </a>
                <a href="{{ route('manage.maintenance.index', ['tab' => 'scheduled']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('tab') === 'scheduled' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="tenancies" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Work Orders</span>
                </a>
                <a href="{{ route('manage.maintenance.schedules.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.maintenance.schedules.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="reports" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Preventive Schedules</span>
                </a>
                <a href="{{ route('manage.maintenance.index', ['tab' => 'vendors']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('tab') === 'vendors' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="users" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Vendors</span>
                </a>
            </x-nav-group>

            <x-nav-group label="Communication" slug="landlord-communication" :active="request()->routeIs('manage.notifications.*', 'manage.messages.*')">
                <a href="{{ route('manage.notifications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.notifications.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="notifications" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak class="flex-1">Notifications</span>
                    <span x-show="!collapsed" x-cloak><x-nav-badge :count="$notificationsActionCount" /></span>
                </a>
                <a href="{{ route('manage.messages.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.messages.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="complaints" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Tenant Messages</span>
                </a>
            </x-nav-group>

            <x-nav-group label="Documents" slug="landlord-documents" :active="request()->routeIs('manage.documents.*', 'manage.contract-templates.*')">
                <a href="{{ route('manage.documents.index', ['category' => 'lease_agreement']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.documents.*') && request('category') === 'lease_agreement' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="documents" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Lease Agreement Files</span>
                </a>
                <a href="{{ route('manage.contract-templates.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request()->routeIs('manage.contract-templates.*') ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="documents" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Contract Templates</span>
                </a>
                <a href="{{ route('manage.documents.index', ['category' => 'property_document']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('category') === 'property_document' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="properties" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Property Documents</span>
                </a>
                <a href="{{ route('manage.documents.index', ['category' => 'receipt']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2 text-sm {{ request('category') === 'receipt' ? 'bg-white/10 text-white border-r-2 border-gold' : 'text-white/70 hover:text-white hover:bg-white/5' }}">
                    <x-nav-icon name="expenses" class="h-5 w-5 shrink-0" />
                    <span x-show="!collapsed" x-cloak>Receipts &amp; Invoices</span>
                </a>
            </x-nav-group>

            <x-nav-group label="Analytics" slug="landlord-analytics" :active="request()->routeIs('manage.analytics.*')">
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
            </x-nav-group>
        @endif
    </nav>

    <div class="border-t border-white/10 p-3">
        {{-- Settings is landlord-only, full stop — no delegable permission
             unlocks it for a manager (see routes/web.php). --}}
        @if (! $isAdmin && ! $isManager)
            <a href="{{ route('manage.settings.index') }}" wire:navigate class="flex items-center gap-2 px-2 py-2 text-sm {{ request()->routeIs('manage.settings.*') ? 'text-white' : 'text-white/60 hover:text-white' }}">
                <x-nav-icon name="settings" class="h-4 w-4 shrink-0" />
                <span x-show="!collapsed" x-cloak>Settings</span>
            </a>
            <a href="{{ route('manage.support.index') }}" wire:navigate class="flex items-center gap-2 px-2 py-2 text-sm {{ request()->routeIs('manage.support.*') ? 'text-white' : 'text-white/60 hover:text-white' }}">
                <x-nav-icon name="notifications" class="h-4 w-4 shrink-0" />
                <span x-show="!collapsed" x-cloak>Support</span>
            </a>
        @endif
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
                <p class="text-[11px] text-white/50">{{ $isAdmin ? 'System Administrator' : ($isManager ? 'Manager / Agent' : 'Landlord') }}</p>
            </div>
        </div>
    </div>
</aside>
