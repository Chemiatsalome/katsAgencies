@props(['image', 'eyebrow' => null, 'title', 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'relative rounded-xl overflow-hidden shadow-sm']) }}>
    <img src="{{ asset('images/properties/' . $image) }}" alt="" class="absolute inset-0 w-full h-full object-cover">
    <div class="absolute inset-0 bg-gradient-to-r from-navy-dark/90 via-navy-dark/70 to-navy-dark/30"></div>
    <div class="relative px-6 py-8 sm:px-8 sm:py-10">
        @if ($eyebrow)
            <p class="text-xs font-semibold text-gold uppercase tracking-wide">{{ $eyebrow }}</p>
        @endif
        <h2 class="mt-1 text-xl sm:text-2xl font-semibold text-white">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-2 text-sm text-white/80 max-w-xl">{{ $subtitle }}</p>
        @endif
        @isset($action)
            <div class="mt-4">
                {{ $action }}
            </div>
        @endisset
    </div>
</div>
