    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Managers &amp; Agents</h2>
                <a href="{{ route('manage.managers.create') }}" wire:navigate class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-navy text-white text-xs sm:text-sm font-medium rounded-md hover:bg-navy-dark">
                    + Invite Manager
                </a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
                <p class="text-sm text-brand-slate max-w-2xl">
                    Managers and agents operate inside your portal on your behalf, scoped to whichever properties and permissions you grant below. They never see your financial totals unless you explicitly allow it.
                </p>

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Name</th>
                                    <th class="text-left px-5 py-2 font-medium">Contact</th>
                                    <th class="text-left px-5 py-2 font-medium">Properties</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                    <th class="px-5 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($managers as $manager)
                                    @php
                                        $displayStatus = $manager->isExpired() ? 'expired' : $manager->status;
                                        $badgeClass = match ($displayStatus) {
                                            'active' => 'bg-green-50 text-green-700',
                                            'invited' => 'bg-amber-50 text-amber-700',
                                            'expired' => 'bg-gray-100 text-brand-slate',
                                            default => 'bg-gray-100 text-brand-slate',
                                        };
                                    @endphp
                                    <tr wire:key="manager-{{ $manager->id }}" class="hover:bg-gray-50">
                                        <td class="px-5 py-3 font-medium text-charcoal">{{ $manager->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">
                                            {{ $manager->email }}
                                            <span class="block text-xs">{{ $manager->phone }}</span>
                                        </td>
                                        <td class="px-5 py-3 text-brand-slate">
                                            @if ($manager->all_properties)
                                                All properties
                                            @else
                                                {{ $manager->properties->pluck('name')->join(', ') ?: '—' }}
                                            @endif
                                        </td>
                                        <td class="px-5 py-3">
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeClass }}">{{ ucfirst($displayStatus) }}</span>
                                        </td>
                                        <td class="px-5 py-3 text-right whitespace-nowrap space-x-2">
                                            @if ($manager->status === 'invited' && ! $manager->isExpired())
                                                <button
                                                    type="button"
                                                    x-data
                                                    @click="navigator.clipboard.writeText('{{ route('manager-invitations.accept', $manager->token) }}')"
                                                    class="text-teal hover:underline text-xs font-medium"
                                                >Copy Link</button>
                                            @endif
                                            @if ($manager->status === 'invited')
                                                <button wire:click="regenerate({{ $manager->id }})" class="text-brand-slate hover:underline text-xs font-medium">Resend</button>
                                                <button wire:click="cancelInvitation({{ $manager->id }})" wire:confirm="Cancel this invitation?" class="text-danger hover:underline text-xs font-medium">Cancel</button>
                                            @endif
                                            <a href="{{ route('manage.managers.edit', $manager) }}" wire:navigate class="text-teal hover:underline text-xs font-medium">Edit</a>
                                            @if ($manager->status !== 'invited')
                                                <a href="{{ route('manage.managers.activity', $manager) }}" wire:navigate class="text-brand-slate hover:underline text-xs font-medium">Activity</a>
                                            @endif
                                            @if ($manager->status === 'active')
                                                <button wire:click="deactivate({{ $manager->id }})" wire:confirm="Deactivate this manager? They will be logged out immediately." class="text-danger hover:underline text-xs font-medium">Deactivate</button>
                                            @elseif ($manager->status === 'inactive')
                                                <button wire:click="reactivate({{ $manager->id }})" class="text-teal hover:underline text-xs font-medium">Reactivate</button>
                                                <button wire:click="remove({{ $manager->id }})" wire:confirm="Permanently remove this manager and their login? This cannot be undone." class="text-danger hover:underline text-xs font-medium">Remove</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-5 py-6 text-center text-gray-400">No managers or agents yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
