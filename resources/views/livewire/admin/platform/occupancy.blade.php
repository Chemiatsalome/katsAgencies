    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Occupancy Overview</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="flex items-center gap-3">
                <label class="text-sm text-brand-slate">Landlord</label>
                <select wire:model.live="landlordId" class="rounded-md border-brand-border text-sm">
                    <option value="">All Landlords</option>
                    @foreach ($landlords as $landlord)
                        <option value="{{ $landlord->id }}">{{ $landlord->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="Total Units" :value="$totalUnits" />
                <x-stat-tile label="Occupied" :value="$statusCounts->get('occupied', 0)" tone="good" />
                <x-stat-tile label="Vacant" :value="$statusCounts->get('vacant', 0)" />
                <x-stat-tile label="Occupancy Rate" value="{{ $occupancyRate }}%" />
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="Reserved" :value="$statusCounts->get('reserved', 0)" tone="warning" />
                <x-stat-tile label="Pending Approval" :value="$statusCounts->get('pending_approval', 0)" tone="warning" />
                <x-stat-tile label="Under Maintenance" :value="$statusCounts->get('under_maintenance', 0)" tone="warning" />
                <x-stat-tile label="Unavailable" :value="$statusCounts->get('unavailable', 0)" tone="critical" />
            </div>

            <div
                wire:key="occupancy-chart-{{ $landlordId }}"
                x-data="occupancyStatusChart(@js($chartRows))"
                x-init="init()"
                class="bg-white shadow-sm rounded-lg border border-brand-border p-5"
            >
                <h3 class="text-sm font-semibold text-charcoal mb-4">Units by Status</h3>
                <div style="height: {{ max(160, count($chartRows) * 40) }}px">
                    <canvas x-ref="canvas"></canvas>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Occupancy by Landlord</h3>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Landlord</th>
                            <th class="text-right px-5 py-2 font-medium">Units</th>
                            <th class="text-right px-5 py-2 font-medium">Occupied</th>
                            <th class="text-right px-5 py-2 font-medium">Vacant</th>
                            <th class="text-right px-5 py-2 font-medium">Occupancy Rate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($byLandlord as $row)
                            <tr wire:key="bl-{{ $row->landlord->id }}" class="hover:bg-gray-50 cursor-pointer" wire:click="$set('landlordId', {{ $row->landlord->id }})">
                                <td class="px-5 py-2.5 font-medium text-charcoal">
                                    <a href="{{ route('admin.landlords.show', $row->landlord) }}" wire:navigate class="hover:underline" onclick="event.stopPropagation()">{{ $row->landlord->name }}</a>
                                </td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->total }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->occupied }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->vacant }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->rate }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-6 text-center text-gray-400">No landlords registered yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div>{{ $byLandlord->links() }}</div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Occupancy by Property</h3>
                </div>

                @forelse ($byProperty as $landlordName => $properties)
                    <div class="border-t border-brand-border first:border-t-0">
                        <div class="px-5 py-2 bg-navy-light">
                            <p class="text-xs font-semibold text-navy uppercase tracking-wide">{{ $landlordName }}</p>
                        </div>
                        <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($properties as $row)
                                    <tr>
                                        <td class="px-5 py-2.5 font-medium text-charcoal w-2/5">{{ $row->property->name }}</td>
                                        <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->total }} units</td>
                                        <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->occupied }} occupied</td>
                                        <td class="px-5 py-2.5 text-right text-brand-slate w-32">{{ $row->rate }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-6 text-center text-gray-400 text-sm">No properties registered yet.</p>
                @endforelse
            </div>
        </div>
    </div>
