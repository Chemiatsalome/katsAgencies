    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Notifications &amp; Alerts</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <div class="flex items-start justify-between gap-4">
                <p class="text-sm text-brand-slate max-w-2xl">System-level notifications sent across the platform — for oversight and troubleshooting delivery issues. Rows addressed to you are highlighted.</p>

                @if ($myUnreadCount > 0)
                    <button wire:click="markAllRead" class="shrink-0 text-sm text-teal hover:underline whitespace-nowrap">Mark all as read ({{ $myUnreadCount }})</button>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="channel" class="rounded-md border-brand-border text-sm">
                    <option value="all">All channels</option>
                    <option value="in_app">In-App</option>
                    <option value="email">Email</option>
                    <option value="sms">SMS</option>
                </select>
                <select wire:model.live="status" class="rounded-md border-brand-border text-sm">
                    <option value="all">All statuses</option>
                    <option value="sent">Sent</option>
                    <option value="delivered">Delivered</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">When</th>
                            <th class="text-left px-5 py-2 font-medium">To</th>
                            <th class="text-left px-5 py-2 font-medium">Event</th>
                            <th class="text-left px-5 py-2 font-medium">Channel</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($notifications as $notification)
                            @php
                                $isMine = $notification->notifiable_type === \App\Models\User::class && $notification->notifiable_id === auth()->id();
                                $isUnread = $isMine && ! $notification->read_at;
                            @endphp
                            <tr wire:key="notif-{{ $notification->id }}" class="{{ $isUnread ? 'bg-offwhite' : '' }}">
                                <td class="px-5 py-2.5 text-brand-slate whitespace-nowrap">{{ $notification->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-5 py-2.5 text-charcoal">{{ $notification->notifiable->name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ str($notification->event_type)->replace('_', ' ')->title() }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ ucfirst(str_replace('_', '-', $notification->channel)) }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$notification->status" /></td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap space-x-3">
                                    @if ($notification->actionUrl())
                                        <a href="{{ $notification->actionUrl() }}" wire:navigate class="text-teal hover:underline text-xs">View →</a>
                                    @endif
                                    @if ($isUnread)
                                        <button wire:click="markRead({{ $notification->id }})" class="text-teal hover:underline text-xs">Mark read</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-center text-gray-400">No notifications recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div>{{ $notifications->links() }}</div>
        </div>
    </div>
