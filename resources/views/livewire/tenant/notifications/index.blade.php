    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Notifications</h2>
                @if ($unreadCount > 0)
                    <button wire:click="markAllRead" class="text-sm text-teal hover:underline">Mark all as read</button>
                @endif
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                <div class="flex items-center gap-2">
                    <button wire:click="$set('filter', 'all')" class="px-3 py-1.5 rounded-md text-sm {{ $filter === 'all' ? 'bg-navy text-white' : 'bg-white border border-brand-border text-brand-slate' }}">All</button>
                    <button wire:click="$set('filter', 'unread')" class="px-3 py-1.5 rounded-md text-sm {{ $filter === 'unread' ? 'bg-navy text-white' : 'bg-white border border-brand-border text-brand-slate' }}">Unread</button>
                    <button wire:click="$set('filter', 'read')" class="px-3 py-1.5 rounded-md text-sm {{ $filter === 'read' ? 'bg-navy text-white' : 'bg-white border border-brand-border text-brand-slate' }}">Read</button>
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border divide-y divide-gray-100">
                    @forelse ($notifications as $notification)
                        <div wire:key="notification-{{ $notification->id }}" class="px-5 py-4 {{ ! $notification->read_at ? 'bg-offwhite' : '' }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-charcoal">{{ $notification->title }}</p>
                                    <p class="text-sm text-brand-slate mt-0.5">{{ $notification->body }}</p>
                                    <p class="text-[11px] text-brand-slate mt-1">{{ $notification->created_at->format('d M Y, H:i') }}</p>
                                </div>
                                @if (! $notification->read_at)
                                    <button wire:click="markRead({{ $notification->id }})" class="shrink-0 text-xs text-teal hover:underline">Mark read</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-gray-400">No notifications yet.</p>
                    @endforelse
                </div>

                <x-per-page-select :paginator="$notifications" />
            </div>
        </div>
    </div>
