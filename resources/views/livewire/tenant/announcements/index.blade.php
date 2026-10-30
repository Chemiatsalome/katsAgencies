    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Announcements</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
                @endif

                <div class="bg-white shadow-sm rounded-lg border border-brand-border divide-y divide-gray-100">
                    @forelse ($announcements as $announcement)
                        <div wire:key="announcement-{{ $announcement->id }}" class="px-5 py-4">
                            <button type="button" wire:click="toggleExpand({{ $announcement->id }})" class="w-full text-left">
                                <p class="text-sm font-medium text-charcoal">
                                    {{ $announcement->title }}
                                    @if ($announcement->related?->replies->isNotEmpty())
                                        <span class="text-xs text-teal ml-1">({{ $announcement->related->replies->count() }} reply)</span>
                                    @endif
                                </p>
                                <p class="text-sm text-brand-slate mt-1">{{ $announcement->body }}</p>
                                <p class="text-[11px] text-brand-slate mt-2">{{ $announcement->created_at->format('d M Y, H:i') }}</p>
                            </button>

                            @if ($expandedMessageId === $announcement->id && $announcement->related)
                                <div class="mt-3 pt-3 border-t border-gray-200 space-y-3">
                                    @foreach ($announcement->related->replies as $reply)
                                        <div class="text-sm">
                                            <span class="font-medium text-charcoal">{{ $reply->user_id === $announcement->related->tenant?->user_id ? 'You' : 'Your Landlord' }}</span>
                                            <span class="text-xs text-brand-slate ml-1">{{ $reply->created_at->format('d M Y H:i') }}</span>
                                            <p class="text-brand-slate whitespace-pre-line">{{ $reply->body }}</p>
                                        </div>
                                    @endforeach

                                    <form wire:submit="reply({{ $announcement->id }})" class="space-y-2">
                                        <textarea wire:model="replyBody" rows="2" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reply to your landlord..."></textarea>
                                        <x-input-error :messages="$errors->get('replyBody')" class="mt-1" />
                                        <button type="submit" class="text-teal hover:underline text-xs font-medium">Send Reply</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-gray-400">No announcements from your landlord yet.</p>
                    @endforelse
                </div>

                <x-per-page-select :paginator="$announcements" />
            </div>
        </div>
    </div>
