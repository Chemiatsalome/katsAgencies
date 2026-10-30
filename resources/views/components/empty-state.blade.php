@props(['icon' => 'properties', 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg border border-brand-border p-10 text-center']) }}>
    <div class="mx-auto h-12 w-12 rounded-full bg-navy-light text-navy flex items-center justify-center">
        <x-nav-icon :name="$icon" class="h-6 w-6" />
    </div>
    <p class="mt-4 font-medium text-charcoal">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 text-sm text-brand-slate max-w-sm mx-auto">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-5">
            {{ $action }}
        </div>
    @endisset
</div>
