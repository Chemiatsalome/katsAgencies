<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-charcoal leading-tight">Support Tickets</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif

            <div class="flex items-center gap-2">
                <label class="text-sm text-brand-slate">Status</label>
                <select wire:model.live="status" class="rounded-md border-gray-300 text-sm">
                    <option value="all">All</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In progress</option>
                    <option value="resolved">Resolved</option>
                </select>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                @forelse ($tickets as $ticket)
                    <div wire:key="ticket-{{ $ticket->id }}" class="border-b border-gray-100 last:border-b-0">
                        <button wire:click="toggleExpand({{ $ticket->id }})" class="w-full flex items-center justify-between gap-4 px-5 py-3 text-left hover:bg-gray-50">
                            <div>
                                <span class="font-medium text-charcoal text-sm">{{ $ticket->subject }}</span>
                                <span class="text-xs text-brand-slate ml-2">{{ $ticket->landlord?->name }} · {{ ucfirst($ticket->category) }} · {{ $ticket->created_at->format('d M Y') }}</span>
                            </div>
                            <x-status-badge :status="$ticket->status" />
                        </button>

                        @if ($expandedTicketId === $ticket->id)
                            <div class="px-5 py-4 bg-gray-50 space-y-3">
                                <p class="text-sm text-charcoal whitespace-pre-line">{{ $ticket->message }}</p>

                                @foreach ($ticket->replies as $reply)
                                    <div class="text-sm border-t border-gray-200 pt-3">
                                        <span class="font-medium text-charcoal">{{ $reply->user?->hasRole('admin') ? 'Admin' : $reply->user?->name }}</span>
                                        <span class="text-xs text-brand-slate ml-1">{{ $reply->created_at->format('d M Y H:i') }}</span>
                                        <p class="text-brand-slate whitespace-pre-line">{{ $reply->body }}</p>
                                    </div>
                                @endforeach

                                @if ($ticket->status !== 'resolved')
                                    <form wire:submit="reply({{ $ticket->id }})" class="pt-3 border-t border-gray-200 space-y-2">
                                        <textarea wire:model="replyBody" rows="2" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reply..."></textarea>
                                        <x-input-error :messages="$errors->get('replyBody')" class="mt-1" />
                                        <button type="submit" class="text-teal hover:underline text-xs font-medium">Send Reply</button>
                                    </form>

                                    <form wire:submit="resolve({{ $ticket->id }})" class="pt-3 border-t border-gray-200 space-y-2">
                                        <textarea wire:model="resolveNote" rows="2" class="w-full rounded-md border-gray-300 text-sm" placeholder="Resolution note (optional)..."></textarea>
                                        <button type="submit" class="text-success hover:underline text-xs font-medium">Mark Resolved</button>
                                    </form>
                                @else
                                    <p class="text-xs text-success pt-3 border-t border-gray-200">Resolved {{ $ticket->resolved_at?->format('d M Y') }} by {{ $ticket->resolvedBy?->name }}.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="px-5 py-6 text-center text-gray-400 text-sm">No support tickets.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
