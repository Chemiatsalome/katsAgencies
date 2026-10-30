@props(['property', 'class' => 'w-full h-full object-cover'])

@php
    // No real photo uploaded yet: fall back to an illustrative stock photo matched
    // to the property's type, never implying it's the actual unit.
    $typeFallbacks = [
        'apartment' => 'modern-apartment-building-01.jpg',
        'residential_compound' => 'residential-compound-01.jpg',
        'individual_house' => 'modern-house-01.jpg',
        'maisonette' => 'maisonette-exterior-01.jpg',
        'commercial_building' => 'modern-apartment-building-01.jpg',
        'mixed_use' => 'modern-apartment-building-01.jpg',
        'villas' => 'modern-villa-01.jpg',
        'other' => 'modern-house-02.jpg',
    ];

    $photoUrl = $property->photoUrl();
    $isFallback = ! $photoUrl;
    $fallbackFile = $typeFallbacks[$property->type] ?? 'modern-house-01.jpg';
    $src = $photoUrl ?? asset('images/properties/' . $fallbackFile);
@endphp


<div class="relative w-full h-full">
    <img
        src="{{ $src }}"
        alt="{{ $property->name }}"
        class="{{ $class }}"
        loading="lazy"
    >

    @if ($isFallback)
        <span
            class="absolute bottom-2 right-2 inline-flex items-center gap-1 rounded-full
                   bg-black/55 px-2.5 py-1 text-[10px] font-medium text-white
                   shadow-sm backdrop-blur-sm"
            title="This is an illustrative image, not an actual photograph of this property."
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 20 20"
                fill="currentColor"
                class="h-3 w-3"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M1 5.75A2.75 2.75 0 0 1 3.75 3h12.5A2.75 2.75 0 0 1 19 5.75v8.5A2.75 2.75 0 0 1 16.25 17H3.75A2.75 2.75 0 0 1 1 14.25v-8.5Zm5.5 2a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5Zm10.5 5.69-3.57-3.57a.75.75 0 0 0-1.06 0l-2.62 2.62-1.12-1.12a.75.75 0 0 0-1.06 0L3 14.94v-.69h13.25c.26 0 .5-.03.75-.08v-.73Z"
                    clip-rule="evenodd"
                />
            </svg>
            Illustrative photo
        </span>
    @endif
</div>

