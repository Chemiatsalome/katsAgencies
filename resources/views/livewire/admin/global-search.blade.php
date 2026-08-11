<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <div class="relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-brand-slate" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input
            type="text"
            wire:model.live.debounce.300ms="query"
            @focus="open = true"
            placeholder="{{ $isAdmin ? 'Search landlords, properties…' : 'Search properties, units, tenants, payments…' }}"
            class="w-72 pl-9 pr-3 py-1.5 text-sm rounded-md border-brand-border focus:border-teal focus:ring-teal"
        />
    </div>

    @if ($query !== '')
        <div x-show="open" class="absolute z-50 mt-2 w-96 max-h-96 overflow-y-auto bg-white rounded-md shadow-lg ring-1 ring-black/5 border border-brand-border">
            @if (empty($results))
                <p class="px-4 py-6 text-sm text-brand-slate text-center">
                    {{ mb_strlen($query) < 2 ? 'Keep typing…' : 'No results found.' }}
                </p>
            @else
                @foreach ($results as $group => $items)
                    <div class="border-b border-brand-border last:border-b-0">
                        <p class="px-4 pt-3 pb-1 text-xs font-semibold text-brand-slate uppercase tracking-wide">{{ $group }}</p>
                        @foreach ($items as $item)
                            <a
                                href="{{ match($group) {
                                    'Landlords' => route('admin.landlords.show', $item),
                                    'Properties' => $isAdmin ? route('admin.landlords.show', $item->landlord) : route('manage.properties.edit', $item),
                                    'Units' => route('manage.houses.edit', $item),
                                    'Tenants' => route('manage.tenants.edit', $item),
                                    'Tenancies' => route('manage.tenancies.show', $item),
                                    'Payments' => route('manage.tenancies.show', $item->tenancy),
                                    default => '#',
                                } }}"
                                wire:navigate
                                class="block px-4 py-2 text-sm text-charcoal hover:bg-offwhite"
                            >
                                @switch($group)
                                    @case('Landlords')
                                        {{ $item->name }} <span class="text-brand-slate">({{ $item->email }})</span>
                                        @break
                                    @case('Properties')
                                        {{ $item->name }} @if($isAdmin)<span class="text-brand-slate">— {{ $item->landlord->name }}</span>@endif
                                        @break
                                    @case('Units')
                                        {{ $item->block->property->name }} — {{ $item->house_number }}
                                        @break
                                    @case('Tenants')
                                        {{ $item->name }} <span class="text-brand-slate">({{ $item->phone }})</span>
                                        @break
                                    @case('Tenancies')
                                        Tenancy #{{ $item->id }} — {{ $item->tenant->name }}
                                        @break
                                    @case('Payments')
                                        {{ $item->reference_number }} — {{ $item->tenancy->tenant->name }}
                                        @break
                                @endswitch
                            </a>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    @endif
</div>
