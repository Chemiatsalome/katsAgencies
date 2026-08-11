    <div>
        <x-slot name="header">
            <div>
                <p class="text-sm text-brand-slate">
                    <a href="{{ route('admin.landlords.index') }}" wire:navigate class="hover:underline">Landlords</a>
                </p>
                <div class="flex items-center gap-3">
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $landlord->name }}</h2>
                    <x-status-badge :status="$landlord->status" />
                </div>
                <p class="text-sm text-brand-slate">{{ $landlord->email }} @if($landlord->phone) · {{ $landlord->phone }} @endif</p>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="Properties" :value="$landlord->properties_count" />
                <x-stat-tile label="Units" :value="$totalUnits" />
                <x-stat-tile label="Occupied" :value="$occupiedUnits" tone="good" />
                <x-stat-tile label="Vacant" :value="$vacantUnits" />
            </div>

            <div class="grid grid-cols-2 gap-4 max-w-xl">
                <x-stat-tile label="Expected Rent (This Month)" value="KSh {{ number_format($expectedRent) }}" />
                <x-stat-tile label="Collected (This Month)" value="KSh {{ number_format($collectedRent) }}" tone="good" />
            </div>
            <p class="text-xs text-brand-slate max-w-xl -mt-3">
                Shown for platform oversight only. Rent, tenants, and day-to-day operations are managed by the landlord in their own portal.
            </p>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Properties</h3>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Name</th>
                            <th class="text-left px-5 py-2 font-medium">Type</th>
                            <th class="text-left px-5 py-2 font-medium">Blocks</th>
                            <th class="text-left px-5 py-2 font-medium">Units</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($properties as $property)
                            <tr>
                                <td class="px-5 py-3 font-medium text-charcoal">{{ $property->name }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ str($property->type)->replace('_', ' ')->title() }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $property->blocks_count }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $property->houses->count() }}</td>
                                <td class="px-5 py-3"><x-status-badge :status="$property->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-6 text-center text-gray-400">No properties registered yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Recent Activity</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse ($activity as $entry)
                        <div class="px-5 py-3 text-sm">
                            <p class="text-charcoal">{{ $entry->description }}</p>
                            <p class="text-xs text-brand-slate mt-0.5">{{ $entry->created_at->diffForHumans() }}</p>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-gray-400 text-sm">No recent activity.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
