    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Arrears</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
                @endif

                <div class="max-w-xs">
                    <x-stat-tile label="Total Arrears" value="KSh {{ number_format($totalArrears) }}" tone="{{ $totalArrears > 0 ? 'critical' : 'neutral' }}" />
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                <th class="text-left px-5 py-2 font-medium">Property / House</th>
                                <th class="text-right px-5 py-2 font-medium">Monthly Rent</th>
                                <th class="text-right px-5 py-2 font-medium">Arrears</th>
                                <th class="text-right px-5 py-2 font-medium">Days Overdue</th>
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
                                    <td class="px-5 py-3 text-right text-danger font-medium">KSh {{ number_format($row->arrears, 2) }}</td>
                                    <td class="px-5 py-3 text-right text-brand-slate">{{ $row->daysOverdue }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">
                                        <button
                                            wire:click="sendReminder({{ $row->tenancy->id }})"
                                            wire:confirm="Send a rent reminder to {{ $row->tenancy->tenant->name }}?"
                                            class="text-xs text-teal hover:underline mr-3"
                                        >Send Reminder</button>
                                        <button wire:click="openNoteForm({{ $row->tenancy->id }})" class="text-xs text-brand-slate hover:underline">Add Note</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-6 text-center text-gray-400">No arrears — everyone is up to date.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
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
                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Note</x-primary-button>
                        <button type="button" wire:click="$set('showNoteForm', false)" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
