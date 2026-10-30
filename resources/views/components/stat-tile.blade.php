@props(['label', 'value', 'href' => null, 'tone' => 'neutral'])

@php
    $toneClasses = match ($tone) {
        'good' => 'text-success',
        'warning' => 'text-warning',
        'critical' => 'text-danger',
        default => 'text-navy',
    };
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'block bg-white overflow-hidden shadow-sm rounded-lg p-3 sm:p-5 border border-brand-border '.($href ? 'hover:shadow-md hover:border-teal/40 transition' : '')]) }}>
    <p class="text-[11px] sm:text-xs font-medium text-brand-slate uppercase tracking-wide">{{ $label }}</p>
    <p class="mt-1 sm:mt-2 text-lg sm:text-2xl font-semibold {{ $toneClasses }}">{{ $value }}</p>
    @isset($slot)
        @if(trim($slot))
            <p class="mt-0.5 sm:mt-1 text-xs sm:text-sm text-brand-slate">{{ $slot }}</p>
        @endif
    @endisset
</{{ $tag }}>
