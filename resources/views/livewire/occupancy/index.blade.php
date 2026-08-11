    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Occupancy</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="Total Units" :value="$totalUnits" />
                <x-stat-tile label="Occupied" :value="$statusCounts->get('occupied', 0)" tone="good" />
                <x-stat-tile label="Vacant" :value="$statusCounts->get('vacant', 0)" href="{{ route('manage.vacancies.index') }}" />
                <x-stat-tile label="Occupancy Rate" value="{{ $occupancyRate }}%" />
            </div>

            <div
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
                    <h3 class="text-sm font-semibold text-charcoal">Occupancy by Property</h3>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Property</th>
                            <th class="text-right px-5 py-2 font-medium">Units</th>
                            <th class="text-right px-5 py-2 font-medium">Occupied</th>
                            <th class="text-right px-5 py-2 font-medium">Occupancy Rate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($byProperty as $row)
                            <tr>
                                <td class="px-5 py-2.5 font-medium text-charcoal">
                                    <a href="{{ route('manage.properties.show', $row->property) }}" wire:navigate class="hover:underline">{{ $row->property->name }}</a>
                                </td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->total }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->occupied }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ $row->rate }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No properties yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
