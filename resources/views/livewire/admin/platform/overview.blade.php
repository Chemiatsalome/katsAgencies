    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Platform Overview</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <p class="text-sm text-brand-slate max-w-2xl">
                Aggregate rent performance across every landlord on the platform — for oversight only. Individual rent management happens in each landlord's own portal.
            </p>

            <div class="flex flex-wrap items-center gap-6">
                <div class="flex items-center gap-3">
                    <label class="text-sm text-brand-slate">Landlord</label>
                    <select wire:model.live="landlordId" class="rounded-md border-brand-border text-sm">
                        <option value="">All Landlords</option>
                        @foreach ($landlords as $landlord)
                            <option value="{{ $landlord->id }}">{{ $landlord->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-3">
                    <label class="text-sm text-brand-slate">Year</label>
                    <select wire:model.live="year" class="rounded-md border-brand-border text-sm">
                        @foreach ($availableYears as $y)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <h3 class="text-sm font-semibold text-charcoal">{{ $year }} Platform Rent</h3>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 max-w-4xl">
                <x-stat-tile label="Expected Rent" value="KSh {{ number_format($totalExpected) }}" />
                <x-stat-tile label="Collected" value="KSh {{ number_format($totalCollected) }}" tone="good" />
                <x-stat-tile label="Outstanding" value="KSh {{ number_format($totalArrears) }}" tone="{{ $totalArrears > 0 ? 'critical' : 'neutral' }}" />
                <x-stat-tile label="Collection Rate" value="{{ $collectionRate }}%" />
            </div>

            <div
                wire:key="rent-chart-{{ $landlordId }}-{{ $year }}"
                x-data="platformRentChart(@js($months))"
                x-init="init()"
                class="bg-white shadow-sm rounded-lg border border-brand-border p-5 max-w-3xl"
            >
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-charcoal">Expected vs Collected Rent</h3>
                    <div class="flex items-center gap-4 text-xs text-brand-slate">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-navy"></span> Expected</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-teal"></span> Collected</span>
                    </div>
                </div>
                <div class="h-64">
                    <canvas x-ref="canvas"></canvas>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden max-w-3xl">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Monthly Rent Performance — {{ $year }}</h3>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Month</th>
                            <th class="text-right px-5 py-2 font-medium">Expected</th>
                            <th class="text-right px-5 py-2 font-medium">Collected</th>
                            <th class="text-right px-5 py-2 font-medium">Collection Rate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($months as $month)
                            <tr>
                                <td class="px-5 py-2.5 text-charcoal">{{ $month['label'] }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($month['expected']) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($month['collected']) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">
                                    {{ $month['expected'] > 0 ? number_format($month['collected'] / $month['expected'] * 100, 1) : '0.0' }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Performance by Landlord — {{ $year }}</h3>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Landlord</th>
                            <th class="text-right px-5 py-2 font-medium">Expected</th>
                            <th class="text-right px-5 py-2 font-medium">Collected</th>
                            <th class="text-right px-5 py-2 font-medium">Outstanding</th>
                            <th class="text-right px-5 py-2 font-medium">Collection Rate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($byLandlord as $row)
                            <tr wire:key="bl-{{ $row->landlord->id }}" class="hover:bg-gray-50 cursor-pointer" wire:click="$set('landlordId', {{ $row->landlord->id }})">
                                <td class="px-5 py-2.5 font-medium text-charcoal">
                                    <a href="{{ route('admin.landlords.show', $row->landlord) }}" wire:navigate class="hover:underline" onclick="event.stopPropagation()">{{ $row->landlord->name }}</a>
                                </td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($row->expected) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($row->collected) }}</td>
                                <td class="px-5 py-2.5 text-right {{ $row->outstanding > 0 ? 'text-danger' : 'text-brand-slate' }}">KSh {{ number_format($row->outstanding) }}</td>
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
    </div>
