    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Tenant Invitations</h2>
                <a href="{{ route('manage.tenant-invitations.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                    + Invite Tenant
                </a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Property / Unit</th>
                                    <th class="text-right px-5 py-2 font-medium">Rent</th>
                                    <th class="text-left px-5 py-2 font-medium">Expires</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                    <th class="px-5 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($invitations as $invitation)
                                    @php
                                        $displayStatus = $invitation->isExpired() && in_array($invitation->status, ['pending', 'opened']) ? 'expired' : $invitation->status;
                                        $badgeClass = match ($displayStatus) {
                                            'completed' => 'bg-green-50 text-green-700',
                                            'opened' => 'bg-blue-50 text-blue-700',
                                            'cancelled' => 'bg-gray-100 text-brand-slate',
                                            'expired' => 'bg-gray-100 text-brand-slate',
                                            default => 'bg-amber-50 text-amber-700',
                                        };
                                    @endphp
                                    <tr wire:key="invitation-{{ $invitation->id }}" class="hover:bg-gray-50">
                                        <td class="px-5 py-3 font-medium text-charcoal">
                                            {{ $invitation->house->block->property->name }}
                                            <span class="block text-xs text-brand-slate font-normal">Block {{ $invitation->house->block->name }} — Unit {{ $invitation->house->house_number }}</span>
                                            @if ($invitation->existingTenant)
                                                <span class="block text-[11px] text-brand-slate font-normal">For existing tenant: {{ $invitation->existingTenant->name }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($invitation->monthly_rent, 2) }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $invitation->expires_at->format('d M Y') }}</td>
                                        <td class="px-5 py-3">
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeClass }}">{{ ucfirst($displayStatus) }}</span>
                                        </td>
                                        <td class="px-5 py-3 text-right whitespace-nowrap space-x-2">
                                            @if ($invitation->isUsable())
                                                <button
                                                    type="button"
                                                    x-data
                                                    @click="navigator.clipboard.writeText('{{ route('invitations.accept', $invitation->token) }}')"
                                                    class="text-teal hover:underline text-xs font-medium"
                                                >Copy Link</button>
                                                <button
                                                    wire:click="cancel({{ $invitation->id }})"
                                                    wire:confirm="Cancel this invitation?"
                                                    class="text-danger hover:underline text-xs font-medium"
                                                >Cancel</button>
                                            @endif
                                            @if ($displayStatus !== 'completed')
                                                <button
                                                    wire:click="regenerate({{ $invitation->id }})"
                                                    wire:confirm="Generate a new link for this invitation? The old link will stop working."
                                                    class="text-brand-slate hover:underline text-xs font-medium"
                                                >Regenerate</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-5 py-6 text-center text-gray-400">No tenant invitations yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <x-per-page-select :paginator="$invitations" />
            </div>
        </div>
    </div>
