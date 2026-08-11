    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Notification History</h2>
        </x-slot>

        <div class="p-6 space-y-6">
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
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Title</th>
                            <th class="text-left px-5 py-2 font-medium">Recipient</th>
                            <th class="text-left px-5 py-2 font-medium">Event</th>
                            <th class="text-left px-5 py-2 font-medium">Channel</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-left px-5 py-2 font-medium">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($notifications as $notification)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-2.5 font-medium text-charcoal">{{ $notification->title }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $notification->notifiable?->name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ ucwords(str_replace('_', ' ', $notification->event_type)) }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ ucfirst($notification->channel) }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$notification->status" /></td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $notification->created_at->format('d M Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">No notifications sent yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
