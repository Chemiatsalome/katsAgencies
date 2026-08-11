    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Notifications &amp; Alerts</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <p class="text-sm text-brand-slate max-w-2xl">System-level notifications sent across the platform — for oversight and troubleshooting delivery issues.</p>

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
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">When</th>
                            <th class="text-left px-5 py-2 font-medium">To</th>
                            <th class="text-left px-5 py-2 font-medium">Event</th>
                            <th class="text-left px-5 py-2 font-medium">Channel</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($notifications as $notification)
                            <tr wire:key="notif-{{ $notification->id }}">
                                <td class="px-5 py-2.5 text-brand-slate whitespace-nowrap">{{ $notification->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-5 py-2.5 text-charcoal">{{ $notification->notifiable->name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ str($notification->event_type)->replace('_', ' ')->title() }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ ucfirst(str_replace('_', '-', $notification->channel)) }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$notification->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-6 text-center text-gray-400">No notifications recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $notifications->links() }}</div>
        </div>
    </div>
