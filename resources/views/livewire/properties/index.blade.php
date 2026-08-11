    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Properties</h2>
                @can('create', \App\Models\Property::class)
                    <a href="{{ route('manage.properties.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                        + Add Property
                    </a>
                @endcan
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Name</th>
                                <th class="text-left px-5 py-2 font-medium">Address</th>
                                <th class="text-left px-5 py-2 font-medium">Blocks</th>
                                <th class="text-left px-5 py-2 font-medium">Houses (Occupied)</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($properties as $property)
                                <tr wire:key="property-{{ $property->id }}">
                                    <td class="px-5 py-3 font-medium text-charcoal">
                                        <a href="{{ route('manage.properties.show', $property) }}" wire:navigate class="hover:underline">{{ $property->name }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $property->address ?? '—' }}</td>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('manage.blocks.index', $property) }}" wire:navigate class="text-teal hover:underline">
                                            {{ $property->blocks_count }} block{{ $property->blocks_count === 1 ? '' : 's' }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-brand-slate">
                                        {{ $property->houses->count() }} ({{ $property->houses->where('status', 'occupied')->count() }})
                                    </td>
                                    <td class="px-5 py-3"><x-status-badge :status="$property->status" /></td>
                                    <td class="px-5 py-3 text-right space-x-3">
                                        <a href="{{ route('manage.properties.edit', $property) }}" wire:navigate class="text-brand-slate hover:text-charcoal">Edit</a>
                                        @can('delete', $property)
                                            <button wire:click="delete({{ $property->id }})" wire:confirm="Delete this property and all its blocks/houses?" class="text-danger hover:text-danger/80">Delete</button>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-6 text-center text-gray-400">No properties yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    <x-per-page-select :paginator="$properties" />
                </div>
            </div>
        </div>
    </div>
