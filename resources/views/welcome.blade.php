<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Kats Agencies') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased font-sans">
        <div class="min-h-screen bg-offwhite flex flex-col">
            <header class="max-w-7xl w-full mx-auto px-6 py-8 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="h-9 w-9 rounded-md bg-navy text-gold flex items-center justify-center font-semibold">K</div>
                    <span class="font-semibold text-charcoal text-lg">Kats Agencies</span>
                </div>

                @if (Route::has('login'))
                    <nav class="flex items-center gap-4">
                        @auth
                            <a href="{{ route('dashboard') }}" class="text-sm font-medium text-brand-slate hover:text-charcoal">Dashboard</a>
                        @else
                            <a href="{{ route('login') }}" class="text-sm font-medium text-brand-slate hover:text-charcoal">Log in</a>
                            <a href="{{ route('register') }}" class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                                List Your Properties
                            </a>
                        @endauth
                    </nav>
                @endif
            </header>

            <main class="flex-1">
                <div class="max-w-7xl w-full mx-auto px-6 py-12 grid gap-12 lg:grid-cols-2 lg:items-center">
                    <div>
                        <p class="text-xs font-semibold text-teal uppercase tracking-wide mb-3">Property &amp; Tenancy Management Platform</p>
                        <h1 class="text-4xl sm:text-5xl font-bold tracking-tight text-charcoal">
                            Run your entire rental business from one place.
                        </h1>
                        <p class="mt-6 text-lg text-brand-slate leading-relaxed">
                            Kats Agencies gives landlords and property managers a single platform for properties,
                            units, tenants and tenancies, rent and finances, maintenance and complaints —
                            end to end, in place of scattered spreadsheets.
                        </p>

                        <div class="mt-8 flex flex-wrap items-center gap-4">
                            @auth
                                <a href="{{ route('dashboard') }}" class="inline-flex items-center px-5 py-3 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                                    Go to Dashboard
                                </a>
                            @else
                                <a href="{{ route('register') }}" class="inline-flex items-center px-5 py-3 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                                    Register as a Landlord
                                </a>
                                <a href="{{ route('login') }}" class="inline-flex items-center px-5 py-3 border border-brand-border text-charcoal text-sm font-medium rounded-md hover:bg-white">
                                    Log in
                                </a>
                            @endauth
                        </div>
                        <p class="mt-3 text-xs text-brand-slate">Landlord accounts are reviewed by an administrator before activation.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-white rounded-lg border border-brand-border shadow-sm p-6">
                            <p class="text-xs font-medium text-brand-slate uppercase tracking-wide">Properties &amp; Units</p>
                            <p class="mt-2 text-2xl font-semibold text-charcoal">Full Hierarchy</p>
                            <p class="mt-1 text-sm text-brand-slate">Property → block → unit, with real occupancy status.</p>
                        </div>
                        <div class="bg-white rounded-lg border border-brand-border shadow-sm p-6">
                            <p class="text-xs font-medium text-brand-slate uppercase tracking-wide">Tenants &amp; Tenancies</p>
                            <p class="mt-2 text-2xl font-semibold text-charcoal">Full History</p>
                            <p class="mt-1 text-sm text-brand-slate">Move-ins, move-outs and lease history, preserved.</p>
                        </div>
                        <div class="bg-white rounded-lg border border-brand-border shadow-sm p-6">
                            <p class="text-xs font-medium text-brand-slate uppercase tracking-wide">Rent &amp; Finance</p>
                            <p class="mt-2 text-2xl font-semibold text-charcoal">Always Current</p>
                            <p class="mt-1 text-sm text-brand-slate">Balances, arrears and deposits calculated automatically.</p>
                        </div>
                        <div class="bg-white rounded-lg border border-brand-border shadow-sm p-6">
                            <p class="text-xs font-medium text-brand-slate uppercase tracking-wide">Maintenance</p>
                            <p class="mt-2 text-2xl font-semibold text-charcoal">Tracked to Close</p>
                            <p class="mt-1 text-sm text-brand-slate">Complaints logged, assigned and resolved.</p>
                        </div>
                        <div class="bg-white rounded-lg border border-brand-border shadow-sm p-6">
                            <p class="text-xs font-medium text-brand-slate uppercase tracking-wide">Reporting</p>
                            <p class="mt-2 text-2xl font-semibold text-charcoal">Real Numbers</p>
                            <p class="mt-1 text-sm text-brand-slate">Occupancy, collection and performance reports.</p>
                        </div>
                        <div class="bg-white rounded-lg border border-brand-border shadow-sm p-6">
                            <p class="text-xs font-medium text-brand-slate uppercase tracking-wide">Tenant Portal</p>
                            <p class="mt-2 text-2xl font-semibold text-charcoal">Self-Service</p>
                            <p class="mt-1 text-sm text-brand-slate">Rent history, balances and receipts, anytime.</p>
                        </div>
                    </div>
                </div>

                <div class="border-t border-brand-border bg-white">
                    <div class="max-w-7xl w-full mx-auto px-6 py-14">
                        <h2 class="text-sm font-semibold text-brand-slate uppercase tracking-wide text-center mb-2">Built for everyone on the platform</h2>
                        <p class="text-center text-brand-slate max-w-2xl mx-auto mb-10">
                            Kats Agencies is a property and tenancy management platform, not just a rent-collection tool —
                            three roles, one connected system.
                        </p>
                        <div class="grid md:grid-cols-3 gap-6">
                            <div class="rounded-lg border border-brand-border p-6">
                                <p class="text-xs font-semibold text-teal uppercase tracking-wide">System Administration</p>
                                <p class="mt-2 font-medium text-charcoal">Platform-level oversight</p>
                                <p class="mt-2 text-sm text-brand-slate leading-relaxed">
                                    Manage landlord accounts and see aggregate performance across every landlord and property
                                    on the platform — occupancy, rent expected vs. collected, and arrears, by year and month.
                                </p>
                                <a href="{{ route('login') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-teal hover:underline">Admin login →</a>
                            </div>
                            <div class="rounded-lg border border-brand-border p-6">
                                <p class="text-xs font-semibold text-navy uppercase tracking-wide">Landlords</p>
                                <p class="mt-2 font-medium text-charcoal">Run your own portfolio</p>
                                <ul class="mt-2 text-sm text-brand-slate leading-relaxed space-y-1 list-disc list-inside">
                                    <li>Multiple properties, blocks and units</li>
                                    <li>Tenants and tenancy contracts</li>
                                    <li>Rent, payments and arrears</li>
                                    <li>Maintenance and property expenses</li>
                                    <li>Tenant communication and notifications</li>
                                    <li>Income, expenses and returns per property</li>
                                </ul>
                                <a href="{{ route('login') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-navy hover:underline">Landlord login →</a>
                            </div>
                            <div class="rounded-lg border border-brand-border p-6">
                                <p class="text-xs font-semibold text-gold uppercase tracking-wide">Tenants</p>
                                <p class="mt-2 font-medium text-charcoal">Manage your tenancy</p>
                                <p class="mt-2 text-sm text-brand-slate leading-relaxed">
                                    View your tenancy, rent and payment history, receive notifications about upcoming or
                                    overdue rent, and submit maintenance requests or complaints — all in one place.
                                </p>
                                <a href="{{ route('login') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-gold hover:underline">Tenant login →</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t border-brand-border bg-white">
                    <div class="max-w-7xl w-full mx-auto px-6 py-14">
                        <h2 class="text-sm font-semibold text-brand-slate uppercase tracking-wide text-center mb-8">How Kats Agencies works</h2>
                        <div class="grid md:grid-cols-3 gap-8">
                            <div class="text-center">
                                <div class="mx-auto h-10 w-10 rounded-full bg-navy-light text-navy flex items-center justify-center font-semibold">1</div>
                                <p class="mt-3 font-medium text-charcoal">Register your portfolio</p>
                                <p class="mt-1 text-sm text-brand-slate">Sign up as a landlord and get approved to list your properties.</p>
                            </div>
                            <div class="text-center">
                                <div class="mx-auto h-10 w-10 rounded-full bg-navy-light text-navy flex items-center justify-center font-semibold">2</div>
                                <p class="mt-3 font-medium text-charcoal">Manage everything in one place</p>
                                <p class="mt-1 text-sm text-brand-slate">Properties, units, tenants, rent, expenses and maintenance from your own portal.</p>
                            </div>
                            <div class="text-center">
                                <div class="mx-auto h-10 w-10 rounded-full bg-navy-light text-navy flex items-center justify-center font-semibold">3</div>
                                <p class="mt-3 font-medium text-charcoal">Tenants stay informed</p>
                                <p class="mt-1 text-sm text-brand-slate">Tenants get their own portal for rent history, balances and complaints.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <footer class="max-w-7xl w-full mx-auto px-6 py-8 text-center text-xs text-brand-slate">
                Kats Agencies — Property &amp; Tenancy Management Platform
            </footer>
        </div>
    </body>
</html>
