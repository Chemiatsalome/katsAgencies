    <x-admin-layout>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $title }}</h2>
        </x-slot>

        <div class="p-6">
            <div class="bg-white shadow-sm rounded-lg border border-brand-border p-10 text-center">
                <div class="mx-auto h-12 w-12 rounded-full bg-navy-light text-navy flex items-center justify-center mb-4">
                    <x-nav-icon name="settings" class="h-6 w-6" />
                </div>
                <p class="text-sm font-medium text-charcoal">{{ $title }} is being built next.</p>
                <p class="text-sm text-brand-slate mt-1">This section of the Admin module is on the build list and will be filled in shortly.</p>
            </div>
        </div>
    </x-admin-layout>
