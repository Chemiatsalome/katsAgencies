<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Access denied — {{ config('app.name', 'Kats Agencies') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased font-sans">
        <div class="min-h-screen bg-offwhite flex flex-col items-center justify-center px-6 text-center">
            <div class="h-12 w-12 rounded-md bg-navy text-gold flex items-center justify-center font-semibold text-lg mb-6">K</div>

            <p class="text-xs font-semibold text-teal uppercase tracking-wide mb-3">403</p>
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-charcoal">
                This page isn't available for your account
            </h1>
            <p class="mt-4 text-brand-slate max-w-md">
                You're signed in, but this page belongs to a different part of Kats Agencies than your account has
                access to. If you think this is wrong, contact your administrator.
            </p>

            <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center px-5 py-3 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                        Go to my dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}" class="inline-flex items-center px-5 py-3 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                        Log in
                    </a>
                @endauth
                <a href="{{ url('/') }}" class="inline-flex items-center px-5 py-3 border border-brand-border text-charcoal text-sm font-medium rounded-md hover:bg-white">
                    Back to home
                </a>
            </div>
        </div>
    </body>
</html>
