    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Notification History</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            {{-- Not in the header slot above — Livewire's reactive wire:id
                 boundary only wraps this $slot, not $header, so a wire:click
                 placed there silently never fires. --}}
            @if ($unreadCount > 0)
                <div class="flex justify-end">
                    <button wire:click="markAllRead" class="text-sm text-teal hover:underline">Mark all as read</button>
                </div>
            @endif

            <div class="max-w-xs">
                <label class="block text-xs font-medium text-brand-slate mb-1">Status</label>
                <select wire:model.live="status" class="rounded-md border-brand-border text-sm w-full">
                    <option value="all">All</option>
                    <option value="sent">Sent</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Title</th>
                            <th class="text-left px-5 py-2 font-medium">Recipient</th>
                            <th class="text-left px-5 py-2 font-medium">Event</th>
                            <th class="text-left px-5 py-2 font-medium">Channel</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-left px-5 py-2 font-medium">Date</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($notifications as $notification)
                            <tr wire:key="notification-{{ $notification->id }}" class="hover:bg-gray-50 {{ ! $notification->read_at ? 'bg-offwhite' : '' }}">
                                <td class="px-5 py-2.5 font-medium text-charcoal">{{ $notification->title }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $notification->notifiable_id === auth()->id() && $notification->notifiable_type === \App\Models\User::class ? 'You' : ($notification->notifiable?->name ?? '—') }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ ucwords(str_replace('_', ' ', $notification->event_type)) }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ ucfirst($notification->channel) }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$notification->status" /></td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $notification->created_at->format('d M Y H:i') }}</td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap space-x-3">
                                    @if ($notification->actionUrl())
                                        <a href="{{ $notification->actionUrl() }}" wire:navigate class="text-xs text-teal hover:underline">View →</a>
                                    @endif
                                    @if (! $notification->read_at)
                                        <button wire:click="markRead({{ $notification->id }})" class="text-xs text-brand-slate hover:underline">Mark read</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-6 text-center text-gray-400">No notifications sent yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
