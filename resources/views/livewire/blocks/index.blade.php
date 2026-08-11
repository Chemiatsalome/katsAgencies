    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-brand-slate">
                        <a href="{{ route('manage.properties.index') }}" wire:navigate class="hover:underline">Properties</a> / {{ $property->name }}
                    </p>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">Blocks</h2>
                </div>
                @can('create', \App\Models\Block::class)
                    <a href="{{ route('manage.blocks.create', $property) }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                        + Add Block
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
                                <th class="text-left px-5 py-2 font-medium">Block</th>
                                <th class="text-left px-5 py-2 font-medium">Houses (Occupied)</th>
                                <th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($blocks as $block)
                                <tr wire:key="block-{{ $block->id }}">
                                    <td class="px-5 py-3 font-medium text-charcoal">{{ $block->name }}</td>
                                    <td class="px-5 py-3 text-brand-slate">
                                        <a href="{{ route('manage.houses.index', $block) }}" wire:navigate class="text-teal hover:underline">
                                            {{ $block->houses_count }} ({{ $block->houses->where('status', 'occupied')->count() }})
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-right space-x-3">
                                        <a href="{{ route('manage.blocks.edit', $block) }}" wire:navigate class="text-brand-slate hover:text-charcoal">Edit</a>
                                        @can('delete', $block)
                                            <button wire:click="delete({{ $block->id }})" wire:confirm="Delete this block and all its houses?" class="text-danger hover:text-danger/80">Delete</button>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-6 text-center text-gray-400">No blocks yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
