@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-brand-border focus:border-teal focus:ring-teal rounded-md shadow-sm transition-colors']) }}>
