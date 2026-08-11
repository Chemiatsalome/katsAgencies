@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-navy text-start text-base font-medium text-navy bg-navy-light focus:outline-none focus:text-navy-dark focus:bg-navy-light focus:border-navy-dark transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-brand-slate hover:text-teal hover:bg-offwhite hover:border-teal/40 focus:outline-none focus:text-teal focus:bg-offwhite focus:border-teal/40 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
