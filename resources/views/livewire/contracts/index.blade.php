    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Contracts</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-danger/10 border border-danger/30 text-danger px-4 py-2 text-sm">{{ session('error') }}</div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 max-w-2xl">
                <x-stat-tile label="Signed" :value="$signedCount" tone="good" href="{{ route('manage.contracts.index', ['status' => 'signed']) }}" />
                <x-stat-tile label="Awaiting Signature" :value="$awaitingSignatureCount" tone="{{ $awaitingSignatureCount > 0 ? 'warning' : 'neutral' }}" href="{{ route('manage.contracts.index', ['status' => 'awaiting_signature']) }}" />
                <x-stat-tile label="No Contract Issued" :value="$noContractCount" tone="{{ $noContractCount > 0 ? 'critical' : 'neutral' }}" href="{{ route('manage.contracts.index', ['status' => 'none']) }}" />
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
                    <label class="text-sm text-brand-slate">Contract Status</label>
                    <select wire:model.live="status" class="rounded-md border-brand-border text-sm">
                        <option value="all">All</option>
                        <option value="none">No contract issued</option>
                        <option value="draft">Draft</option>
                        <option value="generated">Generated (not sent)</option>
                        <option value="awaiting_signature">Awaiting signature</option>
                        <option value="signed">Signed</option>
                        <option value="declined">Declined</option>
                        <option value="expired">Expired</option>
                    </select>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Tenant</th>
                            <th class="text-left px-5 py-2 font-medium">Property / Unit</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-left px-5 py-2 font-medium">Version</th>
                            <th class="text-left px-5 py-2 font-medium">Sent</th>
                            <th class="text-left px-5 py-2 font-medium">Signed</th>
                            <th class="text-right px-5 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tenancies as $tenancy)
                            @php $contract = $tenancy->contract; @endphp
                            <tr wire:key="tenancy-{{ $tenancy->id }}" class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-medium text-charcoal">
                                    <a href="{{ route('manage.tenancies.show', $tenancy) }}" wire:navigate class="hover:underline">{{ $tenancy->tenant->name }}</a>
                                </td>
                                <td class="px-5 py-3 text-brand-slate">{{ $tenancy->house->block->property->name }} — {{ $tenancy->house->house_number }}</td>
                                <td class="px-5 py-3">
                                    @if ($contract)
                                        <x-status-badge :status="$contract->effectiveStatus()" />
                                    @else
                                        <x-status-badge status="none" />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-brand-slate">{{ $contract?->version ?? '—' }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $contract?->sent_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $contract?->signed_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="px-5 py-3 text-right whitespace-nowrap space-x-3">
                                    @can('update', $tenancy)
                                        @if ($contract && $contract->effectiveStatus() === 'awaiting_signature')
                                            <button wire:click="remind({{ $tenancy->id }})" class="text-xs text-warning hover:underline">Remind</button>
                                        @endif
                                        @if ($contract && in_array($contract->status, ['draft', 'generated']))
                                            <button wire:click="sendContract({{ $tenancy->id }})" class="text-xs text-teal hover:underline">Send</button>
                                        @endif
                                        @if (! $contract)
                                            <button wire:click="generate({{ $tenancy->id }})" class="text-xs text-teal hover:underline">Generate</button>
                                        @endif
                                    @endcan
                                    @if ($tenancy->contracts->isNotEmpty())
                                        <button wire:click="toggleHistory({{ $tenancy->id }})" class="text-xs text-brand-slate hover:underline">
                                            {{ $expandedTenancyId === $tenancy->id ? 'Hide History' : 'History ('.$tenancy->contracts->count().')' }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                            @if ($expandedTenancyId === $tenancy->id)
                                <tr wire:key="tenancy-{{ $tenancy->id }}-history" class="bg-offwhite">
                                    <td colspan="7" class="px-5 py-3">
                                        <div class="overflow-x-auto">
                                        <table class="min-w-full text-xs">
                                            <thead class="text-brand-slate">
                                                <tr>
                                                    <th class="text-left py-1 font-medium">Version</th>
                                                    <th class="text-left py-1 font-medium">Status</th>
                                                    <th class="text-left py-1 font-medium">Source</th>
                                                    <th class="text-left py-1 font-medium">Sent</th>
                                                    <th class="text-left py-1 font-medium">Signed By</th>
                                                    <th class="text-left py-1 font-medium">Signed At</th>
                                                    <th class="text-left py-1 font-medium">Created</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                @foreach ($tenancy->contracts as $version)
                                                    <tr>
                                                        <td class="py-1.5 text-charcoal font-medium">v{{ $version->version }}</td>
                                                        <td class="py-1.5"><x-status-badge :status="$version->effectiveStatus()" /></td>
                                                        <td class="py-1.5 text-brand-slate">{{ $version->isLandlordUpload() ? 'Landlord Uploaded' : 'System Template' }}</td>
                                                        <td class="py-1.5 text-brand-slate">{{ $version->sent_at?->format('d M Y H:i') ?? '—' }}</td>
                                                        <td class="py-1.5 text-brand-slate">{{ $version->signed_by_name ?? '—' }}</td>
                                                        <td class="py-1.5 text-brand-slate">{{ $version->signed_at?->format('d M Y H:i') ?? '—' }}</td>
                                                        <td class="py-1.5 text-brand-slate">{{ $version->created_at->format('d M Y H:i') }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-6 text-center text-gray-400">No tenancies match this filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <x-per-page-select :paginator="$tenancies" />
        </div>
    </div>
