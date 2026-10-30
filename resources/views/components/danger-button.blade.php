<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-danger border border-transparent rounded-md font-semibold text-[11px] sm:text-xs text-white uppercase tracking-widest shadow-sm hover:bg-danger/90 hover:shadow active:bg-danger focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
