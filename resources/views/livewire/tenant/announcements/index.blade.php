    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Announcements</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                <div class="bg-white shadow-sm rounded-lg border border-brand-border divide-y divide-gray-100">
                    @forelse ($announcements as $announcement)
                        <div wire:key="announcement-{{ $announcement->id }}" class="px-5 py-4">
                            <p class="text-sm font-medium text-charcoal">{{ $announcement->title }}</p>
                            <p class="text-sm text-brand-slate mt-1">{{ $announcement->body }}</p>
                            <p class="text-[11px] text-brand-slate mt-2">{{ $announcement->created_at->format('d M Y, H:i') }}</p>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-gray-400">No announcements from your landlord yet.</p>
                    @endforelse
                </div>

                <x-per-page-select :paginator="$announcements" />
            </div>
        </div>
    </div>
