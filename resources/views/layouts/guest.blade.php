<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Kats Agencies') }}</title>

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-charcoal antialiased">
        <div class="min-h-screen flex bg-offwhite">
            <div class="hidden lg:block relative lg:w-1/2 xl:w-3/5">
                <img src="{{ asset('images/properties/modern-villa-03.jpg') }}" alt="A Kats Agencies managed property at dusk" class="absolute inset-0 w-full h-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-t from-navy-dark/85 via-navy-dark/40 to-navy-dark/10"></div>
                <div class="relative h-full flex flex-col justify-between p-10 xl:p-14">
                    <a href="/" wire:navigate class="flex items-center gap-2">
                        <div class="h-11 w-11 rounded-md bg-gradient-to-br from-navy to-navy-dark text-gold ring-1 ring-white/10 flex items-center justify-center text-xl font-semibold shadow-sm">K</div>
                        <span class="text-lg font-semibold text-white">Kats Agencies</span>
                    </a>
                    <div>
                        <p class="text-2xl font-semibold text-white leading-snug max-w-md">
                            Run your rental portfolio with confidence.
                        </p>
                        <p class="mt-3 text-sm text-white/75 max-w-sm">
                            Properties, tenants, rent and maintenance — all in one place, for landlords, managers and tenants alike.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex-1 flex flex-col items-center justify-center px-6 py-10">
                <div class="w-full sm:max-w-md">
                    <div class="lg:hidden mb-6 relative h-28 rounded-xl overflow-hidden shadow-sm">
                        <img src="{{ asset('images/properties/modern-villa-03.jpg') }}" alt="A Kats Agencies managed property at dusk" class="absolute inset-0 w-full h-full object-cover">
                        <div class="absolute inset-0 bg-navy-dark/50"></div>
                        <a href="/" wire:navigate class="relative h-full flex items-center gap-2 px-5">
                            <div class="h-9 w-9 rounded-md bg-gradient-to-br from-navy to-navy-dark text-gold ring-1 ring-white/10 flex items-center justify-center text-base font-semibold shadow-sm">K</div>
                            <span class="text-base font-semibold text-white">Kats Agencies</span>
                        </a>
                    </div>

                    <div class="w-full px-6 py-4 bg-white shadow-md overflow-hidden rounded-lg border border-brand-border">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
