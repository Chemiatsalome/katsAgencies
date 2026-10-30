    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Reports</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Report</label>
                    <select wire:model.live="type" class="rounded-md border-brand-border text-sm min-w-[220px]">
                        @foreach ($types as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($isDateFilterable)
                    <div>
                        <label class="block text-xs font-medium text-brand-slate mb-1">Year</label>
                        <select wire:model.live="year" class="rounded-md border-brand-border text-sm">
                            @foreach (range((int) now()->year, (int) now()->year - 4) as $y)
                                <option value="{{ $y }}">{{ $y }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-brand-slate mb-1">Month</label>
                        <select wire:model.live="month" class="rounded-md border-brand-border text-sm">
                            <option value="">All Months</option>
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}">{{ \Illuminate\Support\Carbon::create()->month($m)->format('F') }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <p class="text-xs text-brand-slate self-center">This report always shows current data{{ $type === 'cash_flow' ? ' for the trailing 12 months' : '' }}.</p>
                @endif
                <div class="ml-auto flex gap-2">
                    <button wire:click="exportCsv" class="px-3 py-1.5 text-sm rounded-md border border-brand-border text-charcoal hover:bg-offwhite">Export CSV</button>
                    <button wire:click="exportPdf" class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">Export PDF</button>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            @foreach ($report['columns'] as $column)
                                <th class="text-left px-5 py-2 font-medium whitespace-nowrap">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-gray-50">
                                @foreach ($row as $cell)
                                    <td class="px-5 py-2.5 text-charcoal whitespace-nowrap">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($report['columns']) }}" class="px-5 py-6 text-center text-gray-400">No data for this selection.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-per-page-select :paginator="$rows" />
        </div>
    </div>
