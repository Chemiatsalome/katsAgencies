<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-navy border border-transparent rounded-md font-semibold text-[11px] sm:text-xs text-white uppercase tracking-widest shadow-sm hover:bg-navy-dark hover:shadow focus:bg-navy-dark active:bg-navy-dark focus:outline-none focus:ring-2 focus:ring-teal focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
