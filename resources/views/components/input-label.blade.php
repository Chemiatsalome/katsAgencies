@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-charcoal']) }}>
    {{ $value ?? $slot }}
</label>
