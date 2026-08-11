    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Deposits</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 max-w-2xl">
                <x-stat-tile label="Held Balance" value="KSh {{ number_format($totalHeld, 2) }}" tone="good" />
                <x-stat-tile label="Outstanding Deposits" value="KSh {{ number_format($totalOutstanding, 2) }}" tone="{{ $totalOutstanding > 0 ? 'warning' : 'neutral' }}" />
                <x-stat-tile label="Tenancies" :value="$rows->count()" />
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Tenant</th>
                            <th class="text-left px-5 py-2 font-medium">Property / House</th>
                            <th class="text-right px-5 py-2 font-medium">Required</th>
                            <th class="text-right px-5 py-2 font-medium">Paid</th>
                            <th class="text-right px-5 py-2 font-medium">Outstanding</th>
                            <th class="text-right px-5 py-2 font-medium">Refunded</th>
                            <th class="text-right px-5 py-2 font-medium">Held Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-medium text-charcoal">
                                    <a href="{{ route('manage.tenancies.show', $row->tenancy) }}" wire:navigate class="hover:underline">{{ $row->tenancy->tenant->name }}</a>
                                </td>
                                <td class="px-5 py-3 text-brand-slate">{{ $row->tenancy->house->block->property->name }} — {{ $row->tenancy->house->house_number }}</td>
                                <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($row->required, 2) }}</td>
                                <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($row->paid, 2) }}</td>
                                <td class="px-5 py-3 text-right {{ $row->outstanding > 0 ? 'text-warning font-medium' : 'text-brand-slate' }}">KSh {{ number_format($row->outstanding, 2) }}</td>
                                <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($row->refunded, 2) }}</td>
                                <td class="px-5 py-3 text-right font-medium text-charcoal">KSh {{ number_format($row->heldBalance, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-6 text-center text-gray-400">No tenancies yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
