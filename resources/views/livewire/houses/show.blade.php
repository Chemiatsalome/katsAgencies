    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-brand-slate">
                        <a href="{{ route('manage.properties.show', $house->block->property) }}" wire:navigate class="hover:underline">{{ $house->block->property->name }}</a>
                        / {{ $house->block->name }}
                    </p>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">Unit {{ $house->house_number }}</h2>
                </div>
                <a href="{{ route('manage.houses.edit', $house) }}" wire:navigate class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 border border-brand-border text-charcoal text-xs sm:text-sm font-medium rounded-md hover:bg-offwhite">
                    Edit Unit
                </a>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 grid grid-cols-2 md:grid-cols-4 gap-6 text-sm">
                <div>
                    <p class="text-brand-slate">Type</p>
                    <p class="text-charcoal font-medium">{{ str($house->type)->replace('_', ' ')->title() }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Monthly Rent</p>
                    <p class="text-charcoal font-medium">KSh {{ number_format($house->monthly_rent) }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Deposit</p>
                    <p class="text-charcoal font-medium">KSh {{ number_format($house->deposit_amount) }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Status</p>
                    <x-status-badge :status="$house->status" />
                </div>
                <div>
                    <p class="text-brand-slate">Current Tenant</p>
                    <p class="text-charcoal font-medium">
                        @if ($house->currentTenancy)
                            <a href="{{ route('manage.tenants.show', $house->currentTenancy->tenant) }}" wire:navigate class="hover:underline">{{ $house->currentTenancy->tenant->name }}</a>
                        @else
                            —
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-brand-slate">Rent Status</p>
                    @if ($house->currentTenancy)
                        <x-status-badge :status="$house->currentTenancy->balance() > 0 ? 'overdue' : 'paid'" />
                    @else
                        —
                    @endif
                </div>
                <div>
                    <p class="text-brand-slate">Lease</p>
                    @if ($house->currentTenancy)
                        <x-status-badge :status="$house->currentTenancy->status" />
                    @else
                        —
                    @endif
                </div>
                <div>
                    <p class="text-brand-slate">Lease Expiry</p>
                    <p class="text-charcoal font-medium">{{ $house->currentTenancy?->lease_end_date?->format('d M Y') ?? '—' }}</p>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Occupancy History</h3>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Tenant</th>
                            <th class="text-left px-5 py-2 font-medium">Move In</th>
                            <th class="text-left px-5 py-2 font-medium">Move Out</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tenancies as $tenancy)
                            <tr>
                                <td class="px-5 py-2.5 text-charcoal">
                                    <a href="{{ route('manage.tenancies.show', $tenancy) }}" wire:navigate class="hover:underline">{{ $tenancy->tenant->name }}</a>
                                </td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $tenancy->move_in_date->format('d M Y') }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $tenancy->move_out_date?->format('d M Y') ?? '—' }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$tenancy->status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No tenancy history yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Maintenance History</h3>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Task</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-right px-5 py-2 font-medium">Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($maintenanceTasks as $task)
                            <tr>
                                <td class="px-5 py-2.5 text-charcoal">{{ $task->title }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$task->status" /></td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ $task->actual_cost ? 'KSh '.number_format($task->actual_cost) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-6 text-center text-gray-400">No maintenance history yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
