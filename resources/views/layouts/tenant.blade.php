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

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased" x-data="{ mobileNavOpen: false }">
        <div class="min-h-screen flex bg-offwhite">
            @include('layouts.partials.tenant-sidebar')

            <!-- Mobile sidebar overlay -->
            <div x-show="mobileNavOpen" x-cloak class="fixed inset-0 z-40 lg:hidden">
                <div class="absolute inset-0 bg-charcoal/50" @click="mobileNavOpen = false"></div>
                <div class="relative w-64 h-full bg-navy text-white overflow-y-auto">
                    @include('layouts.partials.tenant-sidebar', ['persistKey' => 'tenant-sidebar-mobile', 'mobile' => true])
                </div>
            </div>

            <div class="flex-1 min-w-0 flex flex-col">
                <!-- Top header -->
                <header class="h-16 bg-white border-b border-brand-border flex items-center justify-between px-4 lg:px-6 sticky top-0 z-30">
                    <div class="flex items-center gap-3">
                        <button @click="mobileNavOpen = true" class="lg:hidden p-2 text-brand-slate hover:text-navy">
                            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <livewire:tenant.notification-bell />

                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                <button class="flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-offwhite">
                                    <div class="h-8 w-8 rounded-full bg-navy-light text-navy flex items-center justify-center text-xs font-semibold">
                                        {{ collect(explode(' ', auth()->user()->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
                                    </div>
                                    <span class="hidden sm:block text-sm font-medium text-charcoal">{{ auth()->user()->name }}</span>
                                    <svg class="h-4 w-4 text-brand-slate" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('tenant.profile')" wire:navigate>Profile</x-dropdown-link>
                                <livewire:layout.logout-button />
                            </x-slot>
                        </x-dropdown>
                    </div>
                </header>

                @if (isset($header))
                    <div class="bg-white border-b border-brand-border px-4 lg:px-6 py-5">
                        <div class="flex items-start gap-3">
                            <button
                                onclick="window.history.length > 1 ? window.history.back() : (window.location.href = '{{ route('dashboard') }}')"
                                class="shrink-0 mt-0.5 p-1.5 rounded-md text-brand-slate hover:text-navy hover:bg-offwhite"
                                title="Back"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <div class="flex-1 min-w-0">
                                {{ $header }}
                            </div>
                        </div>
                    </div>
                @endif

                @php
                    $activeTenancy = auth()->user()->tenant?->tenancies()->active()->latest('move_in_date')->first();
                @endphp
                @if ($activeTenancy?->hasUnsignedContract())
                    <div class="bg-warning/10 border-b border-warning/20 px-4 lg:px-6 py-3">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-charcoal">Action Required</p>
                                @if ($activeTenancy->contract->version > 1)
                                    <p class="text-xs text-brand-slate">Your landlord has issued a revised tenancy agreement (version {{ $activeTenancy->contract->version }}). Please review and sign the updated version.</p>
                                @else
                                    <p class="text-xs text-brand-slate">Your tenancy agreement has not yet been signed. Please review and sign your agreement to activate your tenancy.</p>
                                @endif
                            </div>
                            <a href="{{ route('tenant.contract') }}" wire:navigate class="shrink-0 text-xs font-medium text-white bg-navy rounded-md px-3 py-1.5 hover:bg-navy-dark">Review &amp; Sign</a>
                        </div>
                    </div>
                @endif

                <main class="flex-1 min-w-0">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
