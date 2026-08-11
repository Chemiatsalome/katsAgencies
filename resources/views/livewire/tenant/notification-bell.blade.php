<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button @click="open = ! open" class="relative p-2 rounded-md text-brand-slate hover:text-navy hover:bg-offwhite transition">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        @if ($unreadCount > 0)
            <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center h-4 w-4 rounded-full bg-danger text-white text-[10px] font-semibold">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="open" x-cloak class="absolute right-0 z-50 mt-2 w-80 bg-white rounded-md shadow-lg ring-1 ring-black/5 border border-brand-border">
        <div class="px-4 py-3 border-b border-brand-border flex items-center justify-between">
            <p class="text-sm font-semibold text-charcoal">Notifications</p>
            <a href="{{ route('tenant.notifications.index') }}" wire:navigate class="text-xs text-teal hover:underline">View all</a>
        </div>
        <div class="max-h-80 overflow-y-auto divide-y divide-brand-border">
            @forelse ($recent as $notification)
                <div class="px-4 py-3 {{ ! $notification->read_at ? 'bg-offwhite' : '' }}">
                    <p class="text-sm font-medium text-charcoal">{{ $notification->title }}</p>
                    <p class="text-xs text-brand-slate mt-0.5">{{ $notification->body }}</p>
                    <p class="text-[11px] text-brand-slate mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-brand-slate text-center">No notifications yet.</p>
            @endforelse
        </div>
    </div>
</div>
