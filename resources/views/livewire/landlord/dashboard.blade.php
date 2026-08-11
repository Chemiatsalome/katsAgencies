    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">
                        {{ now()->hour < 12 ? 'Good morning' : (now()->hour < 17 ? 'Good afternoon' : 'Good evening') }}, {{ explode(' ', auth()->user()->name)[0] }}
                    </h2>
                    <p class="text-sm text-brand-slate">Here's how your properties are performing.</p>
                </div>
                <a href="{{ route('manage.properties.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                    + Add Property
                </a>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="propertyId" class="rounded-md border-brand-border text-sm">
                    <option value="all">All Properties</option>
                    @foreach ($properties as $property)
                        <option value="{{ $property->id }}">{{ $property->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="period" class="rounded-md border-brand-border text-sm">
                    <option value="this_month">This Month</option>
                    <option value="last_month">Last Month</option>
                    <option value="last_3_months">Last 3 Months</option>
                    <option value="this_year">This Year</option>
                    <option value="custom">Custom Range</option>
                </select>
                @if ($period === 'custom')
                    <input type="date" wire:model.live="customFrom" class="rounded-md border-brand-border text-sm" />
                    <span class="text-brand-slate text-sm">to</span>
                    <input type="date" wire:model.live="customTo" class="rounded-md border-brand-border text-sm" />
                @endif
            </div>

            <div>
                <h3 class="text-sm font-semibold text-brand-slate uppercase tracking-wide mb-3">My Properties</h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <x-stat-tile label="My Properties" :value="$totalProperties" href="{{ route('manage.properties.index') }}" />
                    <x-stat-tile label="Total Units" :value="$totalUnits" href="{{ route('manage.units.index') }}" />
                    <x-stat-tile label="Occupied" :value="$occupiedUnits" tone="good" />
                    <x-stat-tile label="Vacant" :value="$vacantUnits" tone="{{ $vacantUnits > 0 ? 'warning' : 'neutral' }}" href="{{ route('manage.vacancies.index') }}" />
                    <x-stat-tile label="Occupancy Rate" value="{{ $occupancyRate }}%" href="{{ route('manage.occupancy.index') }}" />
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-brand-slate uppercase tracking-wide mb-3">Financial Performance — This Month</h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <x-stat-tile label="Expected Rent" value="KSh {{ number_format($expected) }}" href="{{ route('manage.rent-charges.index') }}" />
                    <x-stat-tile label="Collected" value="KSh {{ number_format($collected) }}" tone="good" />
                    <x-stat-tile label="Outstanding" value="KSh {{ number_format($outstanding) }}" tone="{{ $outstanding > 0 ? 'warning' : 'neutral' }}" href="{{ route('manage.arrears.index') }}" />
                    <x-stat-tile label="Expenses" value="KSh {{ number_format($expenses) }}" href="{{ route('manage.expenses.index') }}">
                        @if (! is_null($expenseTrend))
                            {{ $expenseTrend >= 0 ? '+' : '' }}{{ $expenseTrend }}% vs last month
                        @endif
                    </x-stat-tile>
                    <x-stat-tile label="Net Income" value="KSh {{ number_format($netIncome) }}" tone="{{ $netIncome >= 0 ? 'good' : 'critical' }}" href="{{ route('manage.finance.overview') }}" />
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border bg-navy-light">
                    <h3 class="text-sm font-semibold text-navy">Requires Your Attention</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    @if ($overdueCount > 0)
                        <a href="{{ route('manage.arrears.index') }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal">🔴 {{ $overdueCount }} tenant{{ $overdueCount === 1 ? '' : 's' }} {{ $overdueCount === 1 ? 'has' : 'have' }} overdue rent — KSh {{ number_format($overdueAmount) }}</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($dueSoonCount > 0)
                        <a href="{{ route('manage.rent-charges.index') }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal">🟡 {{ $dueSoonCount }} tenant{{ $dueSoonCount === 1 ? '' : 's' }} {{ $dueSoonCount === 1 ? 'has' : 'have' }} rent due within 3 days</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($expiringContracts > 0)
                        <a href="{{ route('manage.tenancies.index', ['status' => 'expiring_soon']) }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal">🟡 {{ $expiringContracts }} tenancy agreement{{ $expiringContracts === 1 ? '' : 's' }} expire{{ $expiringContracts === 1 ? 's' : '' }} within 30 days</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($emergencyMaintenance > 0)
                        <a href="{{ route('manage.maintenance.index', ['tab' => 'scheduled']) }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal">🔴 {{ $emergencyMaintenance }} emergency maintenance request{{ $emergencyMaintenance === 1 ? '' : 's' }}</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($openMaintenance > 0)
                        <a href="{{ route('manage.maintenance.index', ['tab' => 'requests']) }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal">🟡 {{ $openMaintenance }} maintenance request{{ $openMaintenance === 1 ? '' : 's' }} awaiting action</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($vacantUnits > 0)
                        <a href="{{ route('manage.vacancies.index') }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal">🟡 {{ $vacantUnits }} vacant unit{{ $vacantUnits === 1 ? '' : 's' }}</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if (! is_null($expenseTrend) && $expenseTrend >= 10)
                        <a href="{{ route('manage.expenses.index') }}" wire:navigate class="flex items-center justify-between px-5 py-3 hover:bg-offwhite">
                            <span class="text-sm text-charcoal">🟡 Property expenses increased by {{ $expenseTrend }}% this month</span>
                            <span class="text-teal text-xs">View →</span>
                        </a>
                    @endif
                    @if ($overdueCount === 0 && $dueSoonCount === 0 && $expiringContracts === 0 && $emergencyMaintenance === 0 && $openMaintenance === 0 && $vacantUnits === 0)
                        <p class="px-5 py-6 text-center text-gray-400 text-sm">Nothing needs your attention right now.</p>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-brand-slate uppercase tracking-wide mb-3">Today</h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <x-stat-tile label="Rent Due Today" value="KSh {{ number_format($dueTodayAmount) }}" />
                    <x-stat-tile label="Rent Overdue" value="KSh {{ number_format($overdueAmount) }}" tone="{{ $overdueAmount > 0 ? 'critical' : 'neutral' }}" />
                    <x-stat-tile label="Maintenance Scheduled" :value="$scheduledToday" />
                    <x-stat-tile label="Contracts Expiring Soon" :value="$expiringContracts" />
                    <x-stat-tile label="Vacant Units" :value="$vacantUnits" />
                    <x-stat-tile label="Tasks Requiring Action" :value="$openMaintenance + $overdueCount" tone="{{ ($openMaintenance + $overdueCount) > 0 ? 'warning' : 'neutral' }}" />
                </div>
            </div>
        </div>
    </div>
