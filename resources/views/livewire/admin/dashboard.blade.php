    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Platform Dashboard</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <x-page-banner
                image="residential-compound-01.jpg"
                eyebrow="Platform Oversight"
                title="Every landlord and property, in one view"
                subtitle="Aggregate performance across the entire Kats Agencies platform."
            />

            <div>
                <h3 class="text-sm font-semibold text-brand-slate uppercase tracking-wide mb-3">Landlords</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <x-stat-tile label="Total Landlords" :value="$totalLandlords" href="{{ route('admin.landlords.index') }}" />
                    <x-stat-tile label="Active Landlords" :value="$activeLandlords" tone="good" />
                    <x-stat-tile label="Pending Applications" :value="$pendingApplications" tone="{{ $pendingApplications > 0 ? 'warning' : 'neutral' }}" href="{{ route('admin.landlords.applications') }}" />
                    <x-stat-tile label="Suspended" :value="$suspendedLandlords" tone="{{ $suspendedLandlords > 0 ? 'critical' : 'neutral' }}" />
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-brand-slate uppercase tracking-wide mb-3">Platform Portfolio</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <x-stat-tile label="Total Properties" :value="$totalProperties" href="{{ route('admin.landlords.properties') }}" />
                    <x-stat-tile label="Total Units" :value="$totalUnits" href="{{ route('admin.platform.occupancy') }}" />
                    <x-stat-tile label="Occupied Units" :value="$occupiedUnits" tone="good" />
                    <x-stat-tile label="Vacant Units" :value="$vacantUnits" />
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-brand-slate uppercase tracking-wide mb-3">Platform Rent Oversight — This Month</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <x-stat-tile label="Active Tenancies" :value="$activeTenancies" />
                    <x-stat-tile label="Rent Under Management" value="KSh {{ number_format($expectedRent) }}" />
                    <x-stat-tile label="Collected" value="KSh {{ number_format($collectedRent) }}" tone="good">
                        @if (! is_null($collectedTrend))
                            {{ $collectedTrend >= 0 ? '+' : '' }}{{ $collectedTrend }}% from last month
                        @endif
                    </x-stat-tile>
                    <x-stat-tile label="Outstanding" value="KSh {{ number_format($outstandingRent) }}" tone="{{ $outstandingRent > 0 ? 'warning' : 'neutral' }}" href="{{ route('admin.platform.overview') }}" />
                </div>
                <p class="text-xs text-brand-slate mt-2 max-w-2xl">
                    Shown for platform-wide oversight. Rent collection, arrears, and tenant management happen inside each landlord's own portal.
                </p>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                <div class="px-5 py-4 border-b border-brand-border flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-charcoal">Recently Registered Landlords</h3>
                    <a href="{{ route('admin.landlords.index') }}" wire:navigate class="text-xs text-teal hover:underline">View all</a>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Name</th>
                            <th class="text-left px-5 py-2 font-medium">Email</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-left px-5 py-2 font-medium">Registered</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentLandlords as $landlord)
                            <tr>
                                <td class="px-5 py-2.5 font-medium text-charcoal">
                                    <a href="{{ route('admin.landlords.show', $landlord) }}" wire:navigate class="hover:underline">{{ $landlord->name }}</a>
                                </td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $landlord->email }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$landlord->status" /></td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $landlord->created_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-6 text-center text-brand-slate">No landlords yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
