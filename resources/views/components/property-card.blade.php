@props(['property'])

@php
    $houses = $property->houses;
    $total = $houses->count();
    $occupied = $houses->where('status', 'occupied')->count();
    $vacant = $houses->where('status', 'vacant')->count();
    $rents = $houses->pluck('monthly_rent')->filter(fn ($r) => (float) $r > 0);
    $minRent = $rents->isNotEmpty() ? $rents->min() : null;
    $maxRent = $rents->isNotEmpty() ? $rents->max() : null;
@endphp

<div class="bg-white shadow-sm rounded-xl border border-brand-border hover:shadow-lg hover:border-teal/40 hover:-translate-y-0.5 transition overflow-hidden flex flex-col">
    <a href="{{ route('manage.properties.show', $property) }}" wire:navigate class="block aspect-[4/3] bg-offwhite overflow-hidden">
        <x-property-photo :property="$property" />
    </a>

    <div class="p-4 flex-1 flex flex-col gap-2">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <a href="{{ route('manage.properties.show', $property) }}" wire:navigate class="font-medium text-charcoal hover:underline truncate block">{{ $property->name }}</a>
                <p class="text-xs text-brand-slate">{{ \Illuminate\Support\Str::headline($property->type) }}</p>
            </div>
            <x-status-badge :status="$property->status" />
        </div>

        @if ($property->address)
            <p class="text-xs text-brand-slate truncate">{{ $property->address }}</p>
        @endif

        <div class="flex items-center gap-3 text-xs text-brand-slate mt-1">
            <span>{{ $total }} {{ Str::plural('unit', $total) }}</span>
            @if ($total > 0)
                <span class="text-success">{{ $occupied }} occupied</span>
                <span class="text-teal">{{ $vacant }} vacant</span>
            @endif
        </div>

        <p class="text-sm font-medium text-charcoal">
            @if ($minRent === null)
                <span class="text-brand-slate font-normal">No units yet</span>
            @elseif ($minRent == $maxRent)
                KSh {{ number_format((float) $minRent, 0) }}/mo
            @else
                KSh {{ number_format((float) $minRent, 0) }}–{{ number_format((float) $maxRent, 0) }}/mo
            @endif
        </p>

        <div class="mt-auto pt-2 flex items-center justify-between">
            <a href="{{ route('manage.properties.show', $property) }}" wire:navigate class="text-sm font-medium text-teal hover:underline">View</a>
            <div class="flex items-center gap-3 text-xs">
                <a href="{{ route('manage.properties.edit', $property) }}" wire:navigate class="text-brand-slate hover:text-charcoal">Edit</a>
                @can('delete', $property)
                    <button wire:click="delete({{ $property->id }})" wire:confirm="Delete this property and all its blocks, houses, tenancy/rent history, and expense/income records? This can't be undone." class="text-danger hover:text-danger/80">Delete</button>
                @endcan
            </div>
        </div>
    </div>
</div>
