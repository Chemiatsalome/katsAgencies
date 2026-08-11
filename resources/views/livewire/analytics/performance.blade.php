    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Property Performance &amp; ROI</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="Total Investment" value="KSh {{ number_format($portfolioInvestment, 2) }}" />
                <x-stat-tile label="Annual Gross Income" value="KSh {{ number_format($portfolioIncome, 2) }}" tone="good" />
                <x-stat-tile label="Annual Expenses" value="KSh {{ number_format($portfolioExpenses, 2) }}" tone="critical" />
                <x-stat-tile label="Net Operating Income" value="KSh {{ number_format($portfolioNetIncome, 2) }}" tone="{{ $portfolioNetIncome >= 0 ? 'good' : 'critical' }}" />
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-x-auto">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Property Comparison</h3>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium whitespace-nowrap">Property</th>
                            <th class="text-right px-5 py-2 font-medium whitespace-nowrap">Investment</th>
                            <th class="text-right px-5 py-2 font-medium whitespace-nowrap">Annual Income</th>
                            <th class="text-right px-5 py-2 font-medium whitespace-nowrap">Annual Expenses</th>
                            <th class="text-right px-5 py-2 font-medium whitespace-nowrap">Net Income</th>
                            <th class="text-right px-5 py-2 font-medium whitespace-nowrap">Gross Yield</th>
                            <th class="text-right px-5 py-2 font-medium whitespace-nowrap">Net Yield</th>
                            <th class="text-right px-5 py-2 font-medium whitespace-nowrap">Occupancy</th>
                            <th class="text-right px-5 py-2 font-medium whitespace-nowrap">Collection</th>
                            <th class="text-left px-5 py-2 font-medium whitespace-nowrap">Health</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-2.5 font-medium text-charcoal whitespace-nowrap">
                                    <a href="{{ route('manage.properties.show', $row->property, ['tab' => 'performance']) }}" wire:navigate class="hover:underline">{{ $row->property->name }}</a>
                                </td>
                                <td class="px-5 py-2.5 text-right text-brand-slate whitespace-nowrap">KSh {{ number_format($row->investment, 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-success whitespace-nowrap">KSh {{ number_format($row->annualIncome, 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-danger whitespace-nowrap">KSh {{ number_format($row->annualExpenses, 2) }}</td>
                                <td class="px-5 py-2.5 text-right font-medium whitespace-nowrap {{ $row->netIncome >= 0 ? 'text-success' : 'text-danger' }}">KSh {{ number_format($row->netIncome, 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate whitespace-nowrap">{{ $row->grossYield !== null ? $row->grossYield.'%' : '—' }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate whitespace-nowrap">{{ $row->netYield !== null ? $row->netYield.'%' : '—' }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate whitespace-nowrap">{{ $row->occupancyRate }}%</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate whitespace-nowrap">{{ $row->collectionRate }}%</td>
                                <td class="px-5 py-2.5 whitespace-nowrap">
                                    <x-status-badge :status="str_replace(' ', '_', strtolower($row->health['rating']))" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-5 py-6 text-center text-gray-400">No properties yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-brand-slate">Gross Yield = Annual Gross Income ÷ Current Value (or Total Investment if no valuation set) × 100. Net Yield = Net Operating Income ÷ Total Investment × 100. Set purchase price, renovation costs, and current estimated value on a property's edit page to populate these figures.</p>
        </div>
    </div>
