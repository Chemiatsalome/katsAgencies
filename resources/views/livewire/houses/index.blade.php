    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-brand-slate">
                        <a href="{{ route('manage.properties.index') }}" wire:navigate class="hover:underline">Properties</a> /
                        <a href="{{ route('manage.blocks.index', $block->property) }}" wire:navigate class="hover:underline">{{ $block->property->name }}</a> /
                        {{ $block->name }}
                    </p>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">Houses</h2>
                </div>
                @can('create', \App\Models\House::class)
                    <a href="{{ route('manage.houses.create', $block) }}" wire:navigate class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-navy text-white text-xs sm:text-sm font-medium rounded-md hover:bg-navy-dark">
                        + Add House
                    </a>
                @endcan
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
                @if (session('status'))
                    <div class="bg-teal/10 border border-teal/30 text-charcoal text-sm rounded-md px-4 py-3">
                        {{ session('status') }}
                    </div>
                @endif

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">House</th>
                                <th class="text-left px-5 py-2 font-medium">Type</th>
                                <th class="text-left px-5 py-2 font-medium">Rent</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                <th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($houses as $house)
                                <tr wire:key="house-{{ $house->id }}">
                                    <td class="px-5 py-3 font-medium text-charcoal">
                                        <a href="{{ route('manage.houses.show', $house) }}" wire:navigate class="hover:underline">{{ $house->house_number }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-brand-slate">{{ str($house->type)->replace('_', ' ')->title() }}</td>
                                    <td class="px-5 py-3 text-brand-slate">KSh {{ number_format($house->monthly_rent) }}</td>
                                    <td class="px-5 py-3"><x-status-badge :status="$house->status" /></td>
                                    <td class="px-5 py-3 text-brand-slate">
                                        @if ($house->currentTenancy)
                                            <a href="{{ route('manage.tenancies.show', $house->currentTenancy) }}" wire:navigate class="text-teal hover:underline">
                                                {{ $house->currentTenancy->tenant->name }}
                                            </a>
                                        @else
                                            <a href="{{ route('manage.tenancies.create', ['house_id' => $house->id]) }}" wire:navigate class="text-gray-400 hover:text-brand-slate">+ Move in tenant</a>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right space-x-3">
                                        <a href="{{ route('manage.houses.edit', $house) }}" wire:navigate class="text-brand-slate hover:text-charcoal">Edit</a>
                                        @can('delete', $house)
                                            <button wire:click="delete({{ $house->id }})" wire:confirm="Delete this house and its tenancy/rent history? This can't be undone." class="text-danger hover:text-danger/80">Delete</button>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-6 text-center text-brand-slate">No houses yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
