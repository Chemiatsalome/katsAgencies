    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Tenancies</h2>
                @can('create', \App\Models\Tenancy::class)
                    <a href="{{ route('manage.tenancies.create') }}" wire:navigate class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-navy text-white text-xs sm:text-sm font-medium rounded-md hover:bg-navy-dark">
                        + Move In Tenant
                    </a>
                @endcan
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                {{-- wire:click actions can't live in the header slot above —
                     Livewire's reactive wire:id boundary only wraps this
                     $slot, not $header (they're two separate blocks in
                     layouts/admin.blade.php), so a button up there never
                     fires. Same gotcha as Tenancies/Show. --}}
                @can('create', \App\Models\Tenancy::class)
                    <div class="flex justify-end gap-3">
                        <button wire:click="openBulkContracts" class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-white border border-brand-border text-charcoal text-xs sm:text-sm font-medium rounded-md hover:bg-offwhite">
                            Bulk Send Contracts
                        </button>
                        <button wire:click="sendInviteToAll" wire:confirm="Generate a portal invite link for every tenant who hasn't been invited yet?" class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-white border border-brand-border text-charcoal text-xs sm:text-sm font-medium rounded-md hover:bg-offwhite">
                            Send Invite to All
                        </button>
                    </div>
                @endcan

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                @if ($generatedInviteLink)
                    <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="bg-teal/5 border border-teal/30 rounded-md px-4 py-3 text-sm space-y-2">
                        <div class="flex items-center justify-between">
                            <p class="text-charcoal font-medium">Invite link for {{ $generatedInviteFor }}</p>
                            <button wire:click="dismissInviteLink" class="text-brand-slate hover:text-charcoal text-xs">Dismiss</button>
                        </div>

                        @if ($generatedInviteWhatsappUrl)
                            <a href="{{ $generatedInviteWhatsappUrl }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-2 px-3 py-1.5 text-sm rounded-md bg-[#25D366] text-white hover:bg-[#1ebe5b]">
                                <x-whatsapp-icon />
                                Send via WhatsApp
                            </a>
                        @else
                            <p class="text-xs text-warning">{{ $generatedInviteFor }} has no phone number on file, so there's no WhatsApp number to send to.</p>
                        @endif

                        <p class="text-brand-slate">Or copy the link and share it another way:</p>
                        <div class="flex items-center gap-2">
                            <input type="text" readonly value="{{ $generatedInviteLink }}" onclick="this.select()" class="flex-1 text-xs bg-white border border-brand-border rounded-md px-3 py-1.5 text-charcoal" />
                            <button type="button" onclick="navigator.clipboard.writeText('{{ $generatedInviteLink }}')" class="px-3 py-1.5 text-xs rounded-md bg-navy text-white hover:bg-navy-dark whitespace-nowrap">Copy</button>
                        </div>
                    </div>
                @endif

                @if (! empty($bulkInvites))
                    <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'start'})" class="bg-teal/5 border border-teal/30 rounded-md overflow-hidden">
                        <div class="px-4 py-3 border-b border-teal/20 flex items-center justify-between">
                            <p class="text-charcoal font-medium text-sm">
                                {{ count($bulkInvites) }} invite link{{ count($bulkInvites) === 1 ? '' : 's' }} generated — send each via WhatsApp, one tap at a time
                            </p>
                            <button wire:click="dismissBulkInvites" class="text-brand-slate hover:text-charcoal text-xs">Dismiss</button>
                        </div>
                        <ul class="divide-y divide-teal/10 max-h-96 overflow-y-auto">
                            @foreach ($bulkInvites as $invite)
                                <li class="px-4 py-2.5 flex items-center justify-between gap-3 text-sm">
                                    <span class="text-charcoal font-medium truncate">{{ $invite['name'] }}</span>
                                    <div class="flex items-center gap-2 shrink-0">
                                        @if ($invite['whatsappUrl'])
                                            <a href="{{ $invite['whatsappUrl'] }}" target="_blank" rel="noopener"
                                               class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-md bg-[#25D366] text-white hover:bg-[#1ebe5b]">
                                                <x-whatsapp-icon class="h-3.5 w-3.5" />
                                                Send
                                            </a>
                                        @else
                                            <span class="text-xs text-warning">No phone on file</span>
                                        @endif
                                        <button type="button" onclick="navigator.clipboard.writeText('{{ $invite['link'] }}')" class="px-2.5 py-1 text-xs rounded-md border border-brand-border text-charcoal hover:bg-white">Copy link</button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($expiringSoon->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-warning/30 overflow-hidden">
                        <div class="px-5 py-3 border-b border-brand-border bg-warning/5">
                            <h3 class="text-sm font-semibold text-charcoal">Contracts Expiring Soon</h3>
                        </div>
                        <ul class="divide-y divide-gray-100">
                            @foreach ($expiringSoon as $expiring)
                                <li wire:key="expiring-{{ $expiring->id }}" class="px-5 py-3 flex items-center justify-between text-sm">
                                    <div>
                                        <span class="font-medium text-charcoal">{{ $expiring->tenant->name }}</span>
                                        <span class="text-brand-slate">— {{ $expiring->house->block->property->name }} / {{ $expiring->house->house_number }}</span>
                                        <span class="text-brand-slate">· Expires {{ $expiring->lease_end_date->format('d M Y') }}</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        @can('update', $expiring)
                                            <button wire:click="openRenew({{ $expiring->id }})" class="text-teal hover:underline text-xs font-medium">Renew</button>
                                            <a href="{{ route('manage.tenancies.show', $expiring) }}" wire:navigate class="text-brand-slate hover:text-charcoal text-xs font-medium">Edit</a>
                                            <a href="{{ route('manage.tenancies.show', $expiring) }}" wire:navigate class="text-danger hover:underline text-xs font-medium">Terminate</a>
                                        @endcan
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

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
                </div>

                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div class="flex gap-2 flex-wrap">
                        @foreach (['all' => 'All', 'active' => 'Active', 'archived' => 'Archived / Past', 'pending' => 'Pending', 'expiring_soon' => 'Expiring Soon', 'notice_given' => 'Notice Given', 'terminated' => 'Terminated', 'completed' => 'Completed'] as $value => $label)
                            <button wire:click="$set('status', '{{ $value }}')" class="px-3 py-1.5 text-sm rounded-md {{ $status === $value ? 'bg-navy text-white' : 'bg-white text-brand-slate border border-brand-border' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    <button wire:click="$set('contractFilter', '{{ $contractFilter === 'needs_contract' ? 'all' : 'needs_contract' }}')" class="px-3 py-1.5 text-sm rounded-md {{ $contractFilter === 'needs_contract' ? 'bg-warning text-white' : 'bg-white text-brand-slate border border-brand-border' }}">
                        Needs a Contract
                    </button>
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                    <th class="text-left px-5 py-2 font-medium">Property</th>
                                    <th class="text-left px-5 py-2 font-medium">Block</th>
                                    <th class="text-left px-5 py-2 font-medium">Unit</th>
                                    <th class="text-left px-5 py-2 font-medium">Monthly Rent</th>
                                    <th class="text-left px-5 py-2 font-medium">Move In</th>
                                    <th class="text-left px-5 py-2 font-medium">Balance</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                    <th class="text-left px-5 py-2 font-medium">Portal Access</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($tenancies as $tenancy)
                                    <tr wire:key="tenancy-{{ $tenancy->id }}" class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('manage.tenancies.show', $tenancy) }}'">
                                        <td class="px-5 py-3 font-medium text-charcoal">{{ $tenancy->tenant->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy->house->block->property->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy->house->block->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy->house->house_number }}</td>
                                        <td class="px-5 py-3 text-brand-slate">KSh {{ number_format($tenancy->monthly_rent) }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy->move_in_date->format('d M Y') }}</td>
                                        <td class="px-5 py-3 {{ $tenancy->balance() > 0 ? 'text-danger' : 'text-brand-slate' }}">KSh {{ number_format($tenancy->balance()) }}</td>
                                        <td class="px-5 py-3"><x-status-badge :status="$tenancy->status" /></td>
                                        <td class="px-5 py-3" onclick="event.stopPropagation()">
                                            @if ($tenancy->tenant->hasPortalAccess())
                                                <x-status-badge status="active" />
                                            @elseif ($tenancy->tenant->isPortalInvited())
                                                <div class="flex items-center gap-2">
                                                    <x-status-badge status="pending" />
                                                    @can('update', $tenancy)
                                                        <button wire:click="sendPortalInvite({{ $tenancy->tenant->id }})" class="text-xs text-teal hover:underline whitespace-nowrap">Resend</button>
                                                    @endcan
                                                </div>
                                            @else
                                                @can('update', $tenancy)
                                                    <button wire:click="sendPortalInvite({{ $tenancy->tenant->id }})" class="px-3 py-1 text-xs rounded-md border border-brand-border text-charcoal hover:bg-offwhite whitespace-nowrap">Send Invite</button>
                                                @else
                                                    <span class="text-xs text-gray-400">Not invited</span>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-5 py-6 text-center text-gray-400">No tenancies found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <x-per-page-select :paginator="$tenancies" />
            </div>
        </div>

        @if ($renewingTenancyId)
            <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="cancelRenew">
                <div class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-sm space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">Renew Tenancy</h3>

                    <form wire:submit="renew" class="space-y-4">
                        <div>
                            <x-input-label for="renew_lease_end_date" value="New Tenancy End Date" />
                            <x-text-input id="renew_lease_end_date" type="date" wire:model="renew_lease_end_date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('renew_lease_end_date')" class="mt-1" />
                        </div>

                        <div class="flex items-center gap-3">
                            <x-primary-button type="submit">Renew</x-primary-button>
                            <button type="button" wire:click="cancelRenew" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if ($showBulkContracts)
            <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" wire:click.self="dismissBulkContracts">
                <div class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-2xl space-y-4 max-h-[85vh] overflow-y-auto">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-charcoal">Bulk Send Contracts</h3>
                        <button type="button" wire:click="dismissBulkContracts" class="text-brand-slate hover:text-charcoal text-xs">Close</button>
                    </div>

                    @if (! empty($bulkContractResults))
                        <div class="bg-teal/5 border border-teal/30 rounded-md overflow-hidden">
                            <ul class="divide-y divide-teal/10 max-h-60 overflow-y-auto">
                                @foreach ($bulkContractResults as $result)
                                    <li class="px-4 py-2 flex items-center justify-between gap-3 text-sm">
                                        <span class="text-charcoal">{{ $result['name'] }}</span>
                                        <span class="text-xs text-brand-slate">{{ $result['result'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <label class="flex items-start gap-2 text-sm text-brand-slate">
                            <input type="checkbox" wire:model.live="includeAlreadySigned" class="mt-0.5 rounded border-brand-border" />
                            <span>Include tenants who already have a signed contract — checking any of these issues a revised agreement, and their original signed contract stays on record.</span>
                        </label>

                        @if ($bulkContractCandidates->isEmpty())
                            <p class="text-sm text-gray-400 text-center py-6">No eligible tenants — everyone active already has a valid signed contract.</p>
                        @else
                            <div class="border border-brand-border rounded-md divide-y divide-gray-100 max-h-80 overflow-y-auto">
                                @foreach ($bulkContractCandidates as $candidate)
                                    <label class="flex items-center gap-3 px-4 py-2 text-sm hover:bg-gray-50">
                                        <input type="checkbox" wire:model="selectedBulkContractTenancyIds" value="{{ $candidate->id }}" class="rounded border-brand-border" />
                                        <span class="flex-1 text-charcoal">{{ $candidate->tenant->name }}</span>
                                        <span class="text-brand-slate text-xs">{{ $candidate->house->block->property->name }} — {{ $candidate->house->house_number }}</span>
                                        <x-status-badge :status="$candidate->contract?->effectiveStatus() ?? 'none'" />
                                    </label>
                                @endforeach
                            </div>

                            <div class="flex items-center gap-3">
                                <button
                                    wire:click="sendBulkContracts"
                                    wire:confirm="Generate and send contracts to {{ count($selectedBulkContractTenancyIds) }} selected tenant(s)?"
                                    @if (empty($selectedBulkContractTenancyIds)) disabled @endif
                                    class="text-sm text-white bg-navy rounded-md px-3 py-1.5 hover:bg-navy-dark disabled:opacity-50"
                                >Send Selected ({{ count($selectedBulkContractTenancyIds) }})</button>
                                <button type="button" wire:click="dismissBulkContracts" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endif
    </div>
