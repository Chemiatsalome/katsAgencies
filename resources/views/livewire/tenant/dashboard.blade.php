    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Tenancy</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                <x-page-banner
                    image="modern-villa-02.jpg"
                    eyebrow="Tenant Portal"
                    :title="'Welcome, ' . ($tenant?->name ?? auth()->user()->name)"
                    subtitle="Your rent, payments and maintenance requests — all in one place."
                />

                @if (! $tenancy)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        No active tenancy is linked to your account yet. Please contact your landlord or the admin.
                    </div>
                @else
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-brand-slate">My Home</p>
                            <p class="text-lg font-semibold text-charcoal">
                                {{ $tenancy->house->block->property->name }}, Block {{ $tenancy->house->block->name }}, Unit {{ $tenancy->house->house_number }}
                            </p>
                        </div>
                        <x-status-badge :status="$tenancy->status" />
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <x-stat-tile label="Monthly Rent" value="KSh {{ number_format($tenancy->monthly_rent) }}" />
                        <x-stat-tile label="Current Balance" value="KSh {{ number_format($balance) }}" tone="{{ $balance > 0 ? 'critical' : 'good' }}" />
                        <x-stat-tile label="Next Rent Due" value="{{ $nextRentDueDate?->format('d M Y') ?? '—' }}" />
                        <x-stat-tile label="Deposit Outstanding" value="KSh {{ number_format($depositBalance) }}" tone="{{ $depositBalance > 0 ? 'warning' : 'good' }}" href="{{ route('tenant.deposit') }}" />
                    </div>

                    @if ($nextCharge)
                        <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4 flex items-center justify-between">
                            <p class="text-sm text-brand-slate">
                                Rent status for {{ \Carbon\Carbon::create()->month($nextCharge->period_month)->format('F') }} {{ $nextCharge->period_year }}
                            </p>
                            <div class="flex items-center gap-3">
                                <x-status-badge :status="$nextCharge->status" />
                                @if ($balance > 0)
                                    <a href="{{ route('tenant.pay') }}" wire:navigate class="px-3 py-1.5 bg-teal text-white text-xs font-medium rounded-md hover:opacity-90">
                                        Pay Now
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <a href="{{ route('tenant.rent') }}" wire:navigate class="bg-white border border-brand-border rounded-lg shadow-sm p-4 text-center hover:shadow-md transition">
                            <p class="text-sm font-medium text-brand-slate flex items-center justify-center gap-1.5">
                                <x-nav-icon name="rent" class="h-4 w-4 shrink-0 text-teal" /> My Rent
                            </p>
                        </a>
                        <a href="{{ route('tenant.notifications.index') }}" wire:navigate class="bg-white border border-brand-border rounded-lg shadow-sm p-4 text-center hover:shadow-md transition">
                            <p class="text-sm font-medium text-brand-slate flex items-center justify-center gap-1.5">
                                <x-nav-icon name="notifications" class="h-4 w-4 shrink-0 text-teal" /> Notifications
                            </p>
                        </a>
                        <a href="{{ route('tenant.profile') }}" wire:navigate class="bg-white border border-brand-border rounded-lg shadow-sm p-4 text-center hover:shadow-md transition">
                            <p class="text-sm font-medium text-brand-slate flex items-center justify-center gap-1.5">
                                <x-nav-icon name="profile" class="h-4 w-4 shrink-0 text-teal" /> My Profile
                            </p>
                        </a>
                        <a href="{{ route('tenant.deposit') }}" wire:navigate class="bg-white border border-brand-border rounded-lg shadow-sm p-4 text-center hover:shadow-md transition">
                            <p class="text-sm font-medium text-brand-slate flex items-center justify-center gap-1.5">
                                <x-nav-icon name="profile" class="h-4 w-4 shrink-0 text-teal" /> My Deposit
                            </p>
                        </a>
                    </div>

                    <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                        <div class="px-5 py-4 border-b border-brand-border">
                            <h3 class="text-sm font-semibold text-brand-slate">Recent Payments</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-brand-slate">
                                    <tr>
                                        <th class="text-left px-5 py-2 font-medium">Date</th>
                                        <th class="text-left px-5 py-2 font-medium">Method</th>
                                        <th class="text-right px-5 py-2 font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($recentPayments as $payment)
                                        <tr>
                                            <td class="px-5 py-2 text-brand-slate">{{ $payment->payment_date->format('d M Y') }}</td>
                                            <td class="px-5 py-2 text-brand-slate">{{ $payment->isOpeningBalance() ? 'Historical balance (not a transaction)' : ucfirst($payment->method) }}</td>
                                            <td class="px-5 py-2 text-right text-charcoal">KSh {{ number_format($payment->amount, 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-5 py-6 text-center text-brand-slate">No payments recorded yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    </div>
