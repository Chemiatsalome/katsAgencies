    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Deposits</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 max-w-3xl">
                <x-stat-tile label="Held Balance" value="KSh {{ number_format($totalHeld, 2) }}" tone="good" />
                <x-stat-tile label="Outstanding Deposits" value="KSh {{ number_format($totalOutstanding, 2) }}" tone="{{ $totalOutstanding > 0 ? 'warning' : 'neutral' }}" />
                <x-stat-tile label="Tenancies" :value="$rows->total()" />
                <x-stat-tile label="Owed a Refund" :value="$notRefundedCount" tone="{{ $notRefundedCount > 0 ? 'critical' : 'good' }}" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by tenant name or phone…" class="rounded-md border-brand-border text-sm w-64" />

                <div class="flex items-center gap-2">
                    <label class="text-sm text-brand-slate">Property</label>
                    <select wire:model.live="propertyId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Properties</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <label class="text-sm text-brand-slate">Block</label>
                    <select wire:model.live="blockId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Blocks</option>
                        @foreach ($blocks as $block)
                            <option value="{{ $block->id }}">{{ $block->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <label class="text-sm text-brand-slate">Deposit Status</label>
                    <select wire:model.live="status" class="rounded-md border-brand-border text-sm">
                        <option value="all">All</option>
                        <option value="outstanding">Outstanding only</option>
                        <option value="settled">Fully paid</option>
                    </select>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Tenant</th>
                            <th class="text-left px-5 py-2 font-medium">Property / House</th>
                            <th class="text-right px-5 py-2 font-medium">Required</th>
                            <th class="text-right px-5 py-2 font-medium">Paid</th>
                            <th class="text-right px-5 py-2 font-medium">Outstanding</th>
                            <th class="text-right px-5 py-2 font-medium">Deducted</th>
                            <th class="text-right px-5 py-2 font-medium">Refunded</th>
                            <th class="text-right px-5 py-2 font-medium">Held Balance</th>
                            <th class="text-left px-5 py-2 font-medium">Refund Status</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-medium text-charcoal">
                                    <a href="{{ route('manage.tenancies.show', $row->tenancy) }}" wire:navigate class="hover:underline">{{ $row->tenancy->tenant->name }}</a>
                                    {{-- A returning tenant leaves more than one row here, same name and
                                         unit — without this, two or three deposit rows for the exact same
                                         person look like duplicate/inconsistent data rather than separate
                                         tenancy periods. --}}
                                    <div class="text-xs text-brand-slate font-normal">
                                        {{ $row->tenancy->move_in_date->format('d M Y') }}
                                        – {{ $row->tenancy->move_out_date?->format('d M Y') ?? 'present' }}
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-brand-slate">{{ $row->tenancy->house->block->property->name }}, {{ $row->tenancy->house->house_number }}</td>
                                <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($row->required, 2) }}</td>
                                <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($row->paid, 2) }}</td>
                                <td class="px-5 py-3 text-right {{ $row->outstanding > 0 ? 'text-warning font-medium' : 'text-brand-slate' }}">KSh {{ number_format($row->outstanding, 2) }}</td>
                                <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($row->deducted, 2) }}</td>
                                <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($row->refunded, 2) }}</td>
                                <td class="px-5 py-3 text-right font-medium text-charcoal">KSh {{ number_format($row->heldBalance, 2) }}</td>
                                <td class="px-5 py-3"><x-status-badge :status="$row->refundStatus" /></td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('manage.tenancies.show', $row->tenancy) }}#deposit" wire:navigate class="text-xs text-teal hover:underline">Manage</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-5 py-6 text-center text-gray-400">No tenancies yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <x-per-page-select :paginator="$rows" />
        </div>
    </div>
