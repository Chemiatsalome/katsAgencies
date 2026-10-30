    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Arrears</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
                @endif

                @if ($pendingWaiverRequests->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-warning/30 overflow-hidden">
                        <div class="px-5 py-3 border-b border-brand-border bg-warning/5">
                            <h3 class="text-sm font-semibold text-charcoal">Pending Late Fee Waiver Requests</h3>
                        </div>
                        <ul class="divide-y divide-gray-100">
                            @foreach ($pendingWaiverRequests as $request)
                                <li wire:key="waiver-request-{{ $request->id }}" class="px-5 py-3 flex items-center justify-between gap-4 text-sm">
                                    <div>
                                        <span class="font-medium text-charcoal">{{ $request->tenancy->tenant->name }}</span>
                                        <span class="text-brand-slate">— {{ $request->tenancy->house->block->property->name }} / {{ $request->tenancy->house->house_number }}</span>
                                        <span class="text-warning font-medium">· KSh {{ number_format($request->requested_amount, 2) }}</span>
                                        @if ($request->reason)
                                            <p class="text-xs text-brand-slate mt-0.5">"{{ $request->reason }}"</p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3 shrink-0">
                                        <button wire:click="acceptWaiverRequest({{ $request->id }})" wire:confirm="Waive this KSh {{ number_format($request->requested_amount, 2) }} late fee for {{ $request->tenancy->tenant->name }}?" class="text-success hover:underline text-xs font-medium">Accept</button>
                                        <button
                                            x-data
                                            @click="let reason = prompt('Reason for declining (optional):'); if (reason === null) return; $wire.rejectWaiverRequest({{ $request->id }}, reason)"
                                            class="text-danger hover:underline text-xs font-medium"
                                        >Reject</button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="max-w-xs">
                    <x-stat-tile label="Total Arrears" value="KSh {{ number_format($totalArrears) }}" tone="{{ $totalArrears > 0 ? 'critical' : 'neutral' }}" />
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
                        <label class="text-sm text-brand-slate">Overdue</label>
                        <select wire:model.live="minDaysOverdue" class="rounded-md border-brand-border text-sm">
                            <option value="all">Any</option>
                            <option value="7">7+ days</option>
                            <option value="30">30+ days</option>
                            <option value="60">60+ days</option>
                            <option value="90">90+ days</option>
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
                                <th class="text-right px-5 py-2 font-medium">Monthly Rent</th>
                                <th class="text-right px-5 py-2 font-medium">Arrears</th>
                                <th class="text-right px-5 py-2 font-medium">Days Overdue</th>
                                <th class="text-left px-5 py-2 font-medium">Notes</th>
                                <th class="text-right px-5 py-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($rows as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 font-medium text-charcoal">
                                        <a href="{{ route('manage.tenancies.show', $row->tenancy) }}" wire:navigate class="hover:underline">{{ $row->tenancy->tenant->name }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $row->tenancy->house->block->property->name }} — {{ $row->tenancy->house->house_number }}</td>
                                    <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($row->tenancy->monthly_rent) }}</td>
                                    <td class="px-5 py-3 text-right text-danger font-medium">
                                        KSh {{ number_format($row->arrears, 2) }}
                                        @if ($row->lateFeeTotal > 0)
                                            <span class="block text-xs text-warning font-normal whitespace-nowrap">incl. +KSh {{ number_format($row->lateFeeTotal, 2) }} late fee</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right text-brand-slate">{{ $row->daysOverdue }}</td>
                                    @php $note = $row->tenancy->tenant->latestNoteEntry(); @endphp
                                    <td class="px-5 py-3 max-w-xs" title="{{ $row->tenancy->tenant->notes }}">
                                        @if ($note)
                                            <p class="text-brand-slate truncate">{{ $note['text'] }}</p>
                                            @if ($note['date'])
                                                <p class="text-xs text-gray-400">{{ $note['date'] }}</p>
                                            @endif
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">
                                        <button
                                            wire:click="sendReminder({{ $row->tenancy->id }})"
                                            wire:confirm="Send a rent reminder to {{ $row->tenancy->tenant->name }}?"
                                            class="text-xs text-teal hover:underline mr-3"
                                        >Send Reminder</button>
                                        <button wire:click="openNoteForm({{ $row->tenancy->id }})" class="text-xs text-brand-slate hover:underline">Add Note</button>
                                        @if ($row->lateFeeTotal > 0)
                                            <button
                                                wire:click="waiveLateFees({{ $row->tenancy->id }})"
                                                wire:confirm="Waive KSh {{ number_format($row->lateFeeTotal, 2) }} in late fees for {{ $row->tenancy->tenant->name }}?"
                                                class="text-xs text-teal hover:underline ml-3"
                                            >Waive Fee</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-6 text-center text-gray-400">No arrears — everyone is up to date.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>

                <x-per-page-select :paginator="$rows" />
            </div>
        </div>

        @if ($showNoteForm)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="$set('showNoteForm', false)">
                <form wire:submit="addNote" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-md space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">Add Arrears Note</h3>
                    <textarea wire:model="noteText" rows="4" class="block w-full rounded-md border-brand-border" placeholder="e.g. Promised to pay by Friday"></textarea>
                    <x-input-error :messages="$errors->get('noteText')" class="mt-1" />

                    <label class="flex items-start gap-2 text-sm text-brand-slate">
                        <input type="checkbox" wire:model="notifyTenant" class="mt-0.5 rounded border-brand-border" />
                        <span>Also send this to the tenant (shows up in their Notifications). Leave unchecked to keep it as a private note only you can see.</span>
                    </label>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Note</x-primary-button>
                        <button type="button" wire:click="$set('showNoteForm', false)" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
