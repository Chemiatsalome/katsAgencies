<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-white border border-brand-border rounded-md font-semibold text-[11px] sm:text-xs text-teal uppercase tracking-widest shadow-sm hover:bg-navy-light hover:shadow focus:outline-none focus:ring-2 focus:ring-teal focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
