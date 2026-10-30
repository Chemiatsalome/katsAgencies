    <div>
        <x-slot name="header">
            <div>
                <h2 class="font-semibold text-xl text-charcoal leading-tight">
                    {{ now()->hour < 12 ? 'Good morning' : (now()->hour < 17 ? 'Good afternoon' : 'Good evening') }}, {{ explode(' ', auth()->user()->name)[0] }}
                </h2>
                <p class="text-sm text-brand-slate">Here's what needs your attention across your assigned properties.</p>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            <div>
                <h3 class="text-sm font-semibold text-brand-slate uppercase tracking-wide mb-3">Assigned Properties</h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <x-stat-tile label="Properties" :value="$totalProperties" href="{{ route('manage.properties.index') }}" />
                    <x-stat-tile label="Total Units" :value="$totalUnits" href="{{ route('manage.units.index') }}" />
                    <x-stat-tile label="Occupied" :value="$occupiedUnits" tone="good" />
                    <x-stat-tile label="Vacant" :value="$vacantUnits" tone="{{ $vacantUnits > 0 ? 'warning' : 'neutral' }}" href="{{ route('manage.vacancies.index') }}" />
                    <x-stat-tile label="Occupancy Rate" value="{{ $occupancyRate }}%" href="{{ route('manage.occupancy.index') }}" />
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border bg-navy-light">
                    <h3 class="text-sm font-semibold text-navy">Requires Your Attention</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    @if ($emergencyMaintenance > 0)
                        <a href="{{ route('manage.maintenance.index') }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-danger shrink-0"></span> {{ $emergencyMaintenance }} emergency maintenance {{ $emergencyMaintenance === 1 ? 'task' : 'tasks' }} open</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($pendingComplaints > 0)
                        <a href="{{ route('manage.maintenance.index', ['tab' => 'requests']) }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-warning shrink-0"></span> {{ $pendingComplaints }} unresolved {{ $pendingComplaints === 1 ? 'complaint' : 'complaints' }}</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($scheduledToday > 0)
                        <a href="{{ route('manage.maintenance.index', ['tab' => 'scheduled']) }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-warning shrink-0"></span> {{ $scheduledToday }} maintenance {{ $scheduledToday === 1 ? 'task' : 'tasks' }} scheduled today</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($pendingApplications > 0)
                        <a href="{{ route('manage.applications.index') }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-warning shrink-0"></span> {{ $pendingApplications }} pending {{ $pendingApplications === 1 ? 'application' : 'applications' }}</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($expiringTenancies > 0)
                        <a href="{{ route('manage.tenancies.index') }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-warning shrink-0"></span> {{ $expiringTenancies }} {{ $expiringTenancies === 1 ? 'tenancy expires' : 'tenancies expire' }} within 30 days</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($canViewRentStatus)
                        <a href="{{ route('manage.rent-charges.index') }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal flex items-center gap-2"><x-nav-icon name="card" class="h-4 w-4 shrink-0 text-brand-slate" /> View rent status for your properties</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($emergencyMaintenance === 0 && $pendingComplaints === 0 && $scheduledToday === 0 && $pendingApplications === 0 && $expiringTenancies === 0)
                        <p class="px-5 py-6 text-center text-gray-400 text-sm">Nothing needs your attention right now.</p>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-brand-slate uppercase tracking-wide mb-3">Operations Overview</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <x-stat-tile label="Tenants" :value="$totalTenants" href="{{ route('manage.tenants.index') }}" />
                    <x-stat-tile label="Open Maintenance" :value="$openMaintenance" href="{{ route('manage.maintenance.index') }}" />
                    <x-stat-tile label="Unresolved Complaints" :value="$pendingComplaints" />
                    <x-stat-tile label="Pending Applications" :value="$pendingApplications" href="{{ route('manage.applications.index') }}" />
                </div>
            </div>
        </div>
    </div>
