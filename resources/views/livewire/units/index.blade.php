    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Units</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
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

                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by unit number…" class="rounded-md border-brand-border text-sm" />

                <select wire:model.live="status" class="rounded-md border-brand-border text-sm">
                    <option value="all">All statuses</option>
                    <option value="vacant">Vacant</option>
                    <option value="occupied">Occupied</option>
                    <option value="reserved">Reserved</option>
                </select>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate sticky top-0">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Property</th>
                                <th class="text-left px-5 py-2 font-medium">Block</th>
                                <th class="text-left px-5 py-2 font-medium">Unit</th>
                                <th class="text-left px-5 py-2 font-medium">Type</th>
                                <th class="text-left px-5 py-2 font-medium">Rent</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="text-left px-5 py-2 font-medium">Current Tenant</th>
                                <th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($houses as $house)
                                <tr wire:key="unit-{{ $house->id }}">
                                    <td class="px-5 py-3 text-brand-slate">{{ $house->block->property->name }}</td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $house->block->name }}</td>
                                    <td class="px-5 py-3 font-medium text-charcoal">
                                        <a href="{{ route('manage.houses.show', $house) }}" wire:navigate class="hover:underline">{{ $house->house_number }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-brand-slate">{{ str($house->type)->replace('_', ' ')->title() }}</td>
                                    <td class="px-5 py-3 text-brand-slate">KSh {{ number_format($house->monthly_rent) }}</td>
                                    <td class="px-5 py-3"><x-status-badge :status="$house->status" /></td>
                                    <td class="px-5 py-3 text-brand-slate">
                                        @if ($house->currentTenancy)
                                            <a href="{{ route('manage.tenancies.show', $house->currentTenancy) }}" wire:navigate class="text-teal hover:underline">
                                                {{ $house->currentTenancy->tenant->name }}
                                            </a>
                                        @elseif ($house->status === 'vacant')
                                            <a href="{{ route('manage.tenancies.create', ['house_id' => $house->id]) }}" wire:navigate class="text-gray-400 hover:text-brand-slate">+ Move in tenant</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <a href="{{ route('manage.houses.edit', $house) }}" wire:navigate class="text-brand-slate hover:text-navy">Edit</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-6 text-center text-gray-400">No units found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <x-per-page-select :paginator="$houses" />
        </div>
    </div>
