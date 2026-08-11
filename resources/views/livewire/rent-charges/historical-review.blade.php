    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Historical Rent Review</h2>
                <a href="{{ route('manage.rent-charges.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Back to Rent &amp; Payments</a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-sm text-brand-slate">
                        Only rent marked paid through a historical assumption, import, or seeded record appears here — genuine payments made through the platform are never listed and cannot be reverted from this page.
                    </p>
                </div>

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
                        <select wire:model.live="blockId" class="rounded-md border-brand-border text-sm" @disabled($propertyId === 'all')>
                            <option value="all">All Blocks</option>
                            @foreach ($blocks as $block)
                                <option value="{{ $block->id }}">{{ $block->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="text-sm text-brand-slate">Year</label>
                        <select wire:model.live="year" class="rounded-md border-brand-border text-sm">
                            @foreach ($availableYears as $y)
                                <option value="{{ $y }}">{{ $y }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="px-5 py-2"><input type="checkbox" wire:model.live="selectAll" class="rounded border-brand-border"></th>
                                    <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                    <th class="text-left px-5 py-2 font-medium">Month</th>
                                    <th class="text-right px-5 py-2 font-medium">Expected</th>
                                    <th class="text-left px-5 py-2 font-medium">Historical Status</th>
                                    <th class="px-5 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($charges as $charge)
                                    @php
                                        $sourceLabel = match ($charge->payments->first(fn ($p) => $p->isRevertibleSource())?->source) {
                                            'seeded_data', 'historical_import' => 'Imported Paid',
                                            default => 'Assumed Paid',
                                        };
                                    @endphp
                                    <tr wire:key="hist-{{ $charge->id }}" class="hover:bg-gray-50">
                                        <td class="px-5 py-3">
                                            <input type="checkbox" wire:model.live="selected" value="{{ $charge->id }}" class="rounded border-brand-border">
                                        </td>
                                        <td class="px-5 py-3 font-medium text-charcoal">{{ $charge->tenancy->tenant->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ \Carbon\Carbon::create()->month($charge->period_month)->format('M') }} {{ $charge->period_year }}</td>
                                        <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($charge->amount_expected, 2) }}</td>
                                        <td class="px-5 py-3">
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">🟡 {{ $sourceLabel }}</span>
                                        </td>
                                        <td class="px-5 py-3 text-right">
                                            @can('update', $charge->tenancy)
                                                <button
                                                    wire:click="revertOne({{ $charge->id }})"
                                                    wire:confirm="This is a historical/imported payment record. Reverting it will remove the paid status and add the applicable rent to the tenant's outstanding balance.&#10;&#10;This action does not affect payments recorded through the platform."
                                                    class="text-danger hover:underline text-xs font-medium"
                                                >Revert</button>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-5 py-6 text-center text-gray-400">No historical/imported rent records for this selection.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($charges->isNotEmpty())
                    <div class="flex items-center gap-3">
                        <button
                            wire:click="revertSelected"
                            wire:confirm="This is a historical/imported payment record. Reverting it will remove the paid status and add the applicable rent to the tenant's outstanding balance.&#10;&#10;This action does not affect payments recorded through the platform."
                            @disabled(empty($selected))
                            class="px-4 py-2 bg-danger text-white text-sm font-medium rounded-md hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed"
                        >Revert Selected to Unpaid ({{ count($selected) }})</button>
                    </div>
                @endif

                @if ($recentReverts->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                        <div class="px-5 py-4 border-b border-brand-border">
                            <h3 class="text-sm font-semibold text-brand-slate">Recent Reverts</h3>
                        </div>
                        <div class="divide-y divide-gray-100">
                            @foreach ($recentReverts as $entry)
                                <div class="px-5 py-3 text-sm">
                                    <p class="text-charcoal">
                                        {{ $entry->getExtraProperty('tenant') }} — {{ $entry->getExtraProperty('month') }}
                                        <span class="text-brand-slate">({{ $entry->getExtraProperty('property') }}, {{ $entry->getExtraProperty('unit') }})</span>
                                    </p>
                                    <p class="text-xs text-brand-slate mt-0.5">
                                        Reverted by {{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at->format('d M Y, H:i') }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
