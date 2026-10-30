    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Activity — {{ $manager->name }}</h2>
                <a href="{{ route('manage.managers.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">
                    Back to Managers
                </a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
                <p class="text-sm text-brand-slate">Everything this manager has done inside your portal, most recent first.</p>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                    <div class="divide-y divide-gray-100">
                        @forelse ($entries as $entry)
                            <div class="px-5 py-3 text-sm flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-charcoal">{{ $entry->description }}</p>
                                    <p class="text-xs text-brand-slate mt-0.5">{{ $entry->created_at->format('d M Y, H:i') }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="px-5 py-6 text-center text-gray-400 text-sm">No activity recorded for this manager yet.</p>
                        @endforelse
                    </div>
                </div>

                <div>{{ $entries->links() }}</div>
            </div>
        </div>
    </div>
