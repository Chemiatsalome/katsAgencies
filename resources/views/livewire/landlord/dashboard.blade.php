
<div>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800">
            My Portfolio
        </h2>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6">

        {{-- Welcome banner --}}
        <x-page-banner
            image="dashboard-hero.jpg"
            eyebrow="Landlord Dashboard"
            :title="(now()->hour < 12 ? 'Good morning' : (now()->hour < 17 ? 'Good afternoon' : 'Good evening')) . ', ' . explode(' ', auth()->user()->name)[0]"
            subtitle="Here's how your properties are performing."
        >
            <x-slot name="action">
                <a href="{{ route('manage.properties.create') }}"
                   wire:navigate
                   class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-blue-50">
                    <span class="text-lg leading-none">+</span>
                    Add Property
                </a>
            </x-slot>
        </x-page-banner>

        {{-- Profile completion notice --}}
        @if ($profileIncomplete)
            <div class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                    <x-nav-icon name="user" class="h-5 w-5" />
                </div>

                <div class="min-w-0 flex-1">
                    <h3 class="font-semibold text-slate-800">
                        Complete your landlord profile
                    </h3>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Missing: {{ implode(' and ', $missingProfileFields) }}.
                    </p>
                    <a href="{{ route('profile') }}"
                       wire:navigate
                       class="mt-2 inline-flex text-sm font-semibold text-amber-800 hover:underline">
                        Complete profile →
                    </a>
                </div>
            </div>
        @endif

        {{-- Rent collection notice --}}
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                        <x-nav-icon name="finance" class="h-5 w-5" />
                    </div>

                    <div class="min-w-0">
                        @if ($directCollectionConfigured)
                            <h3 class="font-semibold text-slate-800">
                                Direct Collection is on
                            </h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                Rent goes straight into your own account.
                            </p>
                        @else
                            <h3 class="font-semibold text-slate-800">
                                Rent collection and payouts
                            </h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                Rent collection and payout arrangements can be reviewed in your settings.
                            </p>

                            @if ($heldPendingDisbursement > 0)
                                <p class="mt-2 inline-flex rounded-lg bg-amber-50 px-3 py-1.5 text-sm font-semibold text-amber-800">
                                    KSh {{ number_format($heldPendingDisbursement, 2) }} held for you
                                </p>
                            @endif
                        @endif
                    </div>
                </div>

                <a href="{{ route('manage.settings.index', ['tab' => 'payout']) }}"
                   wire:navigate
                   class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-800">
                    Payout settings →
                </a>
            </div>
        </section>

        {{-- Financial overview --}}
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

            <div class="flex flex-col gap-4 border-b border-slate-100 p-5 sm:p-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-blue-700">
                        Financial overview
                    </p>
                    <h2 class="mt-1 text-xl font-bold tracking-tight text-slate-900">
                        Rent at a glance
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ match ($period) {
                            'this_month' => 'This month',
                            'last_month' => 'Last month',
                            'last_3_months' => 'Last 3 months',
                            'this_year' => 'This year',
                            'custom' => 'Custom date range',
                            default => 'Selected period',
                        } }}
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <select aria-label="Filter by property"
                            wire:model.live="propertyId"
                            class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm text-slate-700 focus:border-blue-500 focus:ring-blue-500 sm:min-w-44">
                        <option value="all">All properties</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }}</option>
                        @endforeach
                    </select>

                    <select aria-label="Reporting period"
                            wire:model.live="period"
                            class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm text-slate-700 focus:border-blue-500 focus:ring-blue-500 sm:min-w-40">
                        <option value="this_month">This month</option>
                        <option value="last_month">Last month</option>
                        <option value="last_3_months">Last 3 months</option>
                        <option value="this_year">This year</option>
                        <option value="custom">Custom range</option>
                    </select>
                </div>
            </div>

            @if ($period === 'custom')
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50 p-4">
                    <div class="flex items-center gap-2">
                        <label for="custom-from" class="text-sm text-slate-600">From</label>
                        <input id="custom-from"
                               type="date"
                               wire:model.live="customFrom"
                               class="rounded-xl border-slate-200 bg-white text-sm focus:border-blue-500 focus:ring-blue-500" />
                    </div>

                    <div class="flex items-center gap-2">
                        <label for="custom-to" class="text-sm text-slate-600">To</label>
                        <input id="custom-to"
                               type="date"
                               wire:model.live="customTo"
                               class="rounded-xl border-slate-200 bg-white text-sm focus:border-blue-500 focus:ring-blue-500" />
                    </div>
                </div>
            @endif

            <div class="p-5 sm:p-6">

                {{-- Primary financial figures --}}
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

                    {{-- Rent collected --}}
                   
                    <a href="{{ route('manage.finance.overview') }}"
                    wire:navigate
                    class="group relative block overflow-hidden rounded-2xl border border-sky-200 bg-sky-50 p-5 text-slate-900 transition hover:border-sky-300 hover:bg-sky-100">

                        <div class="absolute -right-5 -top-5 h-24 w-24 rounded-full bg-sky-200/60"></div>

                        <div class="relative">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 text-sm font-medium text-sky-800">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-white text-sky-700">
                                        <x-nav-icon name="finance" class="h-4 w-4" />
                                    </span>
                                    Rent collected
                                </div>

                                <span class="text-sky-600 transition group-hover:translate-x-1">→</span>
                            </div>

                            <p class="mt-5 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                                KSh {{ number_format((float) ($collected ?? 0)) }}
                            </p>

                            <p class="mt-2 text-xs text-slate-600">
                                Payments received in the selected period
                            </p>

                            <p class="mt-3 text-xs font-semibold text-sky-800">
                                View financial overview →
                            </p>
                        </div>
                    </a>
                    {{-- Expected rent --}}
                    <a href="{{ route('manage.rent-charges.index') }}"
                       wire:navigate
                       class="group rounded-2xl border border-blue-100 bg-blue-50/70 p-5 transition hover:border-blue-300 hover:bg-blue-50">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2 text-sm font-medium text-blue-800">
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-white text-blue-700">
                                    <x-nav-icon name="finance" class="h-4 w-4" />
                                </span>
                                Expected rent
                            </div>
                            <span class="text-blue-600 transition group-hover:translate-x-1">→</span>
                        </div>
                        <p class="mt-5 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                            KSh {{ number_format($expected) }}
                        </p>
                        <p class="mt-2 text-xs text-slate-500">
                            Rent expected in this period
                        </p>
                        <p class="mt-3 text-xs font-semibold text-blue-700">
                            View rent charges →
                        </p>
                    </a>

                    {{-- Outstanding rent --}}
                    <a href="{{ route('manage.arrears.index') }}"
                       wire:navigate
                       class="group rounded-2xl border border-amber-200 bg-amber-50/70 p-5 transition hover:border-amber-300 hover:bg-amber-50">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2 text-sm font-medium text-amber-800">
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-white text-amber-700">
                                    <x-nav-icon name="warning" class="h-4 w-4" />
                                </span>
                                Outstanding rent
                            </div>
                            <span class="text-amber-700 transition group-hover:translate-x-1">→</span>
                        </div>
                        <p class="mt-5 text-2xl font-bold tracking-tight {{ $outstanding > 0 ? 'text-red-700' : 'text-slate-900' }} sm:text-3xl">
                            KSh {{ number_format($outstanding) }}
                        </p>
                        <p class="mt-2 text-xs text-slate-500">
                            Rent still to be collected
                        </p>
                        <p class="mt-3 text-xs font-semibold text-amber-800">
                            View arrears →
                        </p>
                    </a>

                </div>

                {{-- Secondary financial figures --}}
                <div class="mt-5 grid grid-cols-1 divide-y divide-slate-100 rounded-2xl border border-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">

                    <div class="flex items-center justify-between gap-3 p-4">
                        <div>
                            <p class="text-sm text-slate-500">Expenses</p>
                            <p class="mt-1 text-lg font-bold text-slate-800">
                                KSh {{ number_format($expenses) }}
                            </p>
                        </div>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
                            <x-nav-icon name="finance" class="h-4 w-4" />
                        </span>
                    </div>

                    <div class="flex items-center justify-between gap-3 p-4">
                        <div>
                            <p class="text-sm text-slate-500">Net income</p>
                            <p class="mt-1 text-lg font-bold {{ $netIncome >= 0 ? 'text-emerald-700' : 'text-red-700' }}">
                                KSh {{ number_format($netIncome) }}
                            </p>
                        </div>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                            <x-nav-icon name="finance" class="h-4 w-4" />
                        </span>
                    </div>

                    @if ($lateFeesEnabled)
                        <div class="flex items-center justify-between gap-3 p-4">
                            <div>
                                <p class="text-sm text-slate-500">Late fees</p>
                                <p class="mt-1 text-lg font-bold text-slate-800">
                                    KSh {{ number_format($lateFeesThisMonth) }}
                                </p>
                            </div>
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                                <x-nav-icon name="warning" class="h-4 w-4" />
                            </span>
                        </div>
                    @endif

                </div>

                <div class="mt-4 flex justify-end">
                    <a href="{{ route('manage.finance.overview') }}"
                       wire:navigate
                       class="inline-flex items-center gap-2 text-sm font-semibold text-blue-700 transition hover:text-blue-900">
                        Financial details →
                    </a>
                </div>
            </div>
        </section>

        {{-- Action centre --}}
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-4 border-b border-slate-100 p-5 sm:p-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-amber-700">
                        Action centre
                    </p>
                    <h2 class="mt-1 text-xl font-bold tracking-tight text-slate-900">
                        Needs your attention
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Priority items across your portfolio.
                    </p>
                </div>

                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                    <x-nav-icon name="warning" class="h-5 w-5" />
                </span>
            </div>

            <div class="divide-y divide-slate-100">

                @if ($emergencyMaintenance > 0)
                    <a href="{{ route('manage.maintenance.index', ['tab' => 'scheduled']) }}"
                       wire:navigate
                       class="flex items-center justify-between gap-3 border-l-4 border-red-500 bg-red-50/60 px-4 py-4 transition hover:bg-red-50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-700">
                                <x-nav-icon name="warning" class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="font-semibold text-red-900">Emergency maintenance</p>
                                <p class="mt-1 text-sm text-red-800">
                                    {{ $emergencyMaintenance }} emergency maintenance {{ $emergencyMaintenance === 1 ? 'request needs' : 'requests need' }} attention.
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-red-800">Review →</span>
                    </a>
                @endif

                @if ($overdueCount > 0)
                    <a href="{{ route('manage.arrears.index') }}"
                       wire:navigate
                       class="flex items-center justify-between gap-3 px-4 py-4 transition hover:bg-red-50/50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-700">
                                <x-nav-icon name="warning" class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800">Overdue rent</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $overdueCount }} {{ $overdueCount === 1 ? 'tenant has' : 'tenants have' }} overdue rent — KSh {{ number_format($overdueAmount) }}.
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-blue-700">Review →</span>
                    </a>
                @endif

                @if ($depositOutstandingCount > 0)
                    <a href="{{ route('manage.deposits.index', ['status' => 'outstanding']) }}"
                       wire:navigate
                       class="flex items-center justify-between gap-3 px-4 py-4 transition hover:bg-amber-50/50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                                <x-nav-icon name="finance" class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800">Outstanding deposits</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $depositOutstandingCount }} incomplete security {{ $depositOutstandingCount === 1 ? 'deposit' : 'deposits' }} — KSh {{ number_format($depositOutstandingTotal, 2) }}.
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-blue-700">Review →</span>
                    </a>
                @endif

                @if ($dueSoonCount > 0)
                    <a href="{{ route('manage.rent-charges.index') }}"
                       wire:navigate
                       class="flex items-center justify-between gap-3 px-4 py-4 transition hover:bg-blue-50/50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <x-nav-icon name="finance" class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800">Rent due soon</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    Rent is due within 3 days for {{ $dueSoonCount }} {{ $dueSoonCount === 1 ? 'tenant' : 'tenants' }}.
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-blue-700">Review →</span>
                    </a>
                @endif

                @if ($expiringContracts > 0)
                    <a href="{{ route('manage.tenancies.index', ['status' => 'expiring_soon']) }}"
                       wire:navigate
                       class="flex items-center justify-between gap-3 px-4 py-4 transition hover:bg-violet-50/50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
                                <x-nav-icon name="finance" class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800">Agreements expiring soon</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $expiringContracts }} tenancy {{ $expiringContracts === 1 ? 'agreement expires' : 'agreements expire' }} within 30 days.
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-blue-700">Review →</span>
                    </a>
                @endif

                @if ($openMaintenance > 0)
                    <a href="{{ route('manage.maintenance.index', ['tab' => 'requests']) }}"
                       wire:navigate
                       class="flex items-center justify-between gap-3 px-4 py-4 transition hover:bg-orange-50/50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-700">
                                <x-nav-icon name="warning" class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800">Open maintenance requests</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $openMaintenance }} maintenance {{ $openMaintenance === 1 ? 'request awaiting' : 'requests awaiting' }} action.
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-blue-700">Review →</span>
                    </a>
                @endif

                @if ($vacantUnits > 0)
                    <a href="{{ route('manage.vacancies.index') }}"
                       wire:navigate
                       class="flex items-center justify-between gap-3 px-4 py-4 transition hover:bg-blue-50/50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <x-nav-icon name="finance" class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800">Vacant units</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $vacantUnits }} vacant {{ $vacantUnits === 1 ? 'unit' : 'units' }} across your portfolio.
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-blue-700">View vacancies →</span>
                    </a>
                @endif

                @if (!is_null($expenseTrend) && $expenseTrend >= 10)
                    <a href="{{ route('manage.expenses.index') }}"
                       wire:navigate
                       class="flex items-center justify-between gap-3 px-4 py-4 transition hover:bg-amber-50/50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                                <x-nav-icon name="finance" class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800">Expenses have increased</p>
                                <p class="mt-1 text-sm text-slate-500">
                                    Expenses increased by {{ $expenseTrend }}% this month.
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-blue-700">Review →</span>
                    </a>
                @endif

                @if ($overdueCount === 0 && $depositOutstandingCount === 0 && $dueSoonCount === 0 && $expiringContracts === 0 && $emergencyMaintenance === 0 && $openMaintenance === 0 && $vacantUnits === 0 && (is_null($expenseTrend) || $expenseTrend < 10))
                    <div class="px-5 py-9 text-center">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                            <span class="text-xl">✓</span>
                        </span>
                        <h3 class="mt-3 font-semibold text-slate-800">
                            You're all caught up
                        </h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Nothing currently needs your attention.
                        </p>
                    </div>
                @endif

            </div>
        </section>

        {{-- Portfolio summary --}}
        <section class="space-y-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-blue-700">
                        Portfolio overview
                    </p>
                    <h2 class="mt-1 text-xl font-bold tracking-tight text-slate-900">
                        Your portfolio
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        A quick view of your properties and occupancy.
                    </p>
                </div>

                <a href="{{ route('manage.properties.index') }}"
                   wire:navigate
                   class="text-sm font-semibold text-blue-700 transition hover:text-blue-900">
                    Manage properties →
                </a>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">

                {{-- Properties --}}
                <a href="{{ route('manage.properties.index') }}"
                   wire:navigate
                   class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-md sm:p-5">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium text-slate-500">Properties</p>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                            <x-nav-icon name="finance" class="h-4 w-4" />
                        </span>
                    </div>
                    <p class="mt-4 text-3xl font-bold tracking-tight text-slate-900">
                        {{ number_format($totalProperties) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">In your portfolio</p>
                </a>

                {{-- Total units --}}
                <a href="{{ route('manage.units.index') }}"
                   wire:navigate
                   class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-violet-200 hover:shadow-md sm:p-5">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium text-slate-500">Total units</p>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
                            <x-nav-icon name="finance" class="h-4 w-4" />
                        </span>
                    </div>
                    <p class="mt-4 text-3xl font-bold tracking-tight text-slate-900">
                        {{ number_format($totalUnits) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">Across your properties</p>
                </a>

                {{-- Occupancy rate --}}
                <a href="{{ route('manage.occupancy.index') }}"
                   wire:navigate
                   class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md sm:p-5">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium text-slate-500">Occupancy rate</p>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                            <x-nav-icon name="finance" class="h-4 w-4" />
                        </span>
                    </div>
                    <p class="mt-4 text-3xl font-bold tracking-tight text-slate-900">
                        {{ $occupancyRate }}%
                    </p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-emerald-500"
                             style="width: {{ min(100, max(0, $occupancyRate)) }}%">
                        </div>
                    </div>
                </a>

                {{-- Vacant units --}}
                <a href="{{ route('manage.vacancies.index') }}"
                   wire:navigate
                   class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-amber-200 hover:shadow-md sm:p-5">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium text-slate-500">Vacant units</p>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl {{ $vacantUnits > 0 ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }}">
                            <x-nav-icon name="finance" class="h-4 w-4" />
                        </span>
                    </div>
                    <p class="mt-4 text-3xl font-bold tracking-tight text-slate-900">
                        {{ number_format($vacantUnits) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $vacantUnits > 0 ? 'Units available for occupancy' : 'No vacant units' }}
                    </p>
                </a>

            </div>

            {{-- Late fee settings --}}
            @unless ($lateFeesEnabled)
                <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-slate-600 shadow-sm">
                            <x-nav-icon name="finance" class="h-4 w-4" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-800">
                                Late fee settings
                            </p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Review your settings if you want to enable late fees and rent reminders.
                            </p>
                        </div>
                    </div>

                    <a href="{{ route('manage.settings.index') }}"
                       wire:navigate
                       class="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
                        Explore settings →
                    </a>
                </div>
            @endunless
        </section>

    </div>
</div>
