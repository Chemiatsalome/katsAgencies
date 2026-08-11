    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Vacancies</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <div class="max-w-xs">
                <x-stat-tile label="Estimated Lost Rent" value="KSh {{ number_format($totalLostRent) }}" tone="{{ $totalLostRent > 0 ? 'critical' : 'neutral' }}" />
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Unit</th>
                            <th class="text-left px-5 py-2 font-medium">Property</th>
                            <th class="text-right px-5 py-2 font-medium">Monthly Rent</th>
                            <th class="text-right px-5 py-2 font-medium">Vacant Since</th>
                            <th class="text-right px-5 py-2 font-medium">Days Vacant</th>
                            <th class="text-right px-5 py-2 font-medium">Estimated Lost Rent</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('manage.houses.show', $row->house) }}'">
                                <td class="px-5 py-2.5 font-medium text-charcoal">{{ $row->house->house_number }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $row->house->block->property->name }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($row->house->monthly_rent) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ \Illuminate\Support\Carbon::parse($row->vacantSince)->format('d M Y') }}</td>
                                <td class="px-5 py-2.5 text-right {{ $row->daysVacant > 30 ? 'text-danger' : 'text-brand-slate' }}">{{ $row->daysVacant }}</td>
                                <td class="px-5 py-2.5 text-right text-danger">KSh {{ number_format($row->estimatedLostRent) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">No vacant units — full occupancy.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
