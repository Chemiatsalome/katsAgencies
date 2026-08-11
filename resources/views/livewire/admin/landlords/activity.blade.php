    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Landlord Activity</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <p class="text-sm text-brand-slate max-w-2xl">A live feed of actions taken by landlords across the platform — properties added, tenancies changed, payments recorded.</p>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                <div class="divide-y divide-gray-100">
                    @forelse ($entries as $entry)
                        <div class="px-5 py-3 text-sm flex items-start justify-between gap-4">
                            <div>
                                <p class="text-charcoal">
                                    <span class="font-medium">{{ $entry->causer?->name ?? 'Unknown' }}</span>
                                    {{ $entry->description }}
                                </p>
                                <p class="text-xs text-brand-slate mt-0.5">{{ $entry->created_at->format('d M Y, H:i') }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-gray-400 text-sm">No landlord activity recorded yet.</p>
                    @endforelse
                </div>
            </div>

            <div>{{ $entries->links() }}</div>
        </div>
    </div>
