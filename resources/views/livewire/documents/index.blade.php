    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Documents</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif
            <x-input-error :messages="$errors->get('delete')" />

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 max-w-2xl">
                <x-stat-tile label="Signed Leases" :value="$signedCount" tone="good" />
                <x-stat-tile label="Awaiting Signature" :value="$awaitingSignatureCount" tone="{{ $awaitingSignatureCount > 0 ? 'warning' : 'neutral' }}" href="{{ route('manage.documents.index', ['category' => 'lease_agreement']) }}" />
                <x-stat-tile label="No Lease Sent Yet" :value="$noContractCount" tone="{{ $noContractCount > 0 ? 'critical' : 'neutral' }}" />
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Category</label>
                    <select wire:model.live="category" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Categories</option>
                        @foreach ($categoryOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Status</label>
                    <select wire:model.live="contractStatus" class="rounded-md border-brand-border text-sm">
                        <option value="all">Any Status</option>
                        <option value="none">No Contract</option>
                        <option value="draft">Draft</option>
                        <option value="generated">Generated</option>
                        <option value="sent">Sent</option>
                        <option value="awaiting_signature">Awaiting Signature</option>
                        <option value="signed">Signed</option>
                        <option value="declined">Declined</option>
                        <option value="expired">Expired</option>
                        <option value="superseded">Superseded</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Tenant</label>
                    <select wire:model.live="tenantId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Tenants</option>
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Property</label>
                    <select wire:model.live="propertyId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Properties</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Block</label>
                    <select wire:model.live="blockId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Blocks</option>
                        @foreach ($blocks as $block)
                            <option value="{{ $block->id }}">{{ $block->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Unit</label>
                    <select wire:model.live="houseId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Units</option>
                        @foreach ($houses as $house)
                            <option value="{{ $house->id }}">{{ $house->house_number }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">From</label>
                    <input type="date" wire:model.live="dateFrom" class="rounded-md border-brand-border text-sm" />
                </div>

                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">To</label>
                    <input type="date" wire:model.live="dateTo" class="rounded-md border-brand-border text-sm" />
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">File</th>
                            <th class="text-left px-5 py-2 font-medium">Category</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-left px-5 py-2 font-medium">Attached To</th>
                            <th class="text-left px-5 py-2 font-medium">Uploaded</th>
                            <th class="text-right px-5 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($documents as $document)
                            @php
                                $subject = $document->documentable;
                                $label = match (true) {
                                    $subject instanceof \App\Models\Property => 'Property: '.$subject->name,
                                    $subject instanceof \App\Models\House => 'Unit: '.$subject->house_number,
                                    $subject instanceof \App\Models\Tenancy => 'Tenancy: '.$subject->tenant->name,
                                    $subject instanceof \App\Models\Tenant => 'Tenant: '.$subject->name,
                                    $subject instanceof \App\Models\Expense => 'Expense: '.($subject->vendor ?: $subject->description ?: '—'),
                                    $subject instanceof \App\Models\MaintenanceTask => 'Maintenance: '.$subject->title,
                                    $subject instanceof \App\Models\Complaint => 'Complaint: '.$subject->subject,
                                    default => '—',
                                };
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-2.5">
                                    <a href="{{ $document->url() }}" target="_blank" class="text-teal hover:underline">{{ $document->original_filename }}</a>
                                </td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $categoryOptions[$document->category] ?? $document->category }}</td>
                                <td class="px-5 py-2.5">
                                    @if ($document->contract)
                                        <x-status-badge :status="$document->contract->effectiveStatus()" />
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $label }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $document->created_at->format('d M Y') }}</td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap space-x-3">
                                    @if ($document->contract)
                                        @if ($document->contract->effectiveStatus() === 'awaiting_signature')
                                            <button wire:click="remindToSign({{ $document->id }})" class="text-xs text-warning hover:underline">Remind</button>
                                        @endif
                                        @if ($subject instanceof \App\Models\Tenancy)
                                            <a href="{{ route('manage.tenancies.show', $subject) }}" wire:navigate class="text-xs text-teal hover:underline">View Tenancy</a>
                                        @endif
                                    @else
                                        @can('delete', $document)
                                            <button wire:click="delete({{ $document->id }})" wire:confirm="Delete this document?" class="text-xs text-danger hover:underline">Delete</button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-center text-gray-400">No documents uploaded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <x-per-page-select :paginator="$documents" />
        </div>
    </div>
