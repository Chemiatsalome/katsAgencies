    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Landlord Properties</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <p class="text-sm text-brand-slate max-w-2xl">Every property registered on the platform, across all landlords — read-only oversight.</p>

            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by property name…" class="rounded-md border-brand-border text-sm" />

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Property</th>
                            <th class="text-left px-5 py-2 font-medium">Landlord</th>
                            <th class="text-left px-5 py-2 font-medium">Type</th>
                            <th class="text-left px-5 py-2 font-medium">Blocks</th>
                            <th class="text-left px-5 py-2 font-medium">Units</th>
                            <th class="text-left px-5 py-2 font-medium">Occupied</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($properties as $property)
                            <tr wire:key="prop-{{ $property->id }}">
                                <td class="px-5 py-3 font-medium text-charcoal">{{ $property->name }}</td>
                                <td class="px-5 py-3 text-brand-slate">
                                    <a href="{{ route('admin.landlords.show', $property->landlord) }}" wire:navigate class="text-teal hover:underline">{{ $property->landlord->name }}</a>
                                </td>
                                <td class="px-5 py-3 text-brand-slate">{{ str($property->type)->replace('_', ' ')->title() }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $property->blocks_count }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $property->houses->count() }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $property->houses->where('status', 'occupied')->count() }}</td>
                                <td class="px-5 py-3"><x-status-badge :status="$property->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-6 text-center text-gray-400">No properties registered yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $properties->links() }}</div>
        </div>
    </div>
