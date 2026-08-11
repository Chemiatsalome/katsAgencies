    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-brand-slate">
                        <a href="{{ route('manage.tenants.index') }}" wire:navigate class="hover:underline">Tenants</a>
                    </p>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $tenant->name }}</h2>
                    <p class="text-sm text-brand-slate">{{ $tenant->phone }} @if($tenant->email) · {{ $tenant->email }} @endif</p>
                </div>
                <a href="{{ route('manage.tenants.edit', $tenant) }}" wire:navigate class="inline-flex items-center px-4 py-2 border border-brand-border text-charcoal text-sm font-medium rounded-md hover:bg-offwhite">
                    Edit Tenant
                </a>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            @if ($currentTenancy)
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <x-stat-tile label="Unit" value="{{ $currentTenancy->house->house_number }}" href="{{ route('manage.houses.show', $currentTenancy->house) }}">{{ $currentTenancy->house->block->property->name }}</x-stat-tile>
                    <x-stat-tile label="Monthly Rent" value="KSh {{ number_format($currentTenancy->monthly_rent) }}" />
                    <x-stat-tile label="Balance" value="KSh {{ number_format($currentTenancy->balance()) }}" tone="{{ $currentTenancy->balance() > 0 ? 'critical' : 'good' }}" href="{{ route('manage.tenancies.show', $currentTenancy) }}" />
                    <x-stat-tile label="Lease Status" value="{{ str($currentTenancy->status)->replace('_', ' ')->title() }}" />
                </div>
            @else
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-sm text-brand-slate">No active tenancy for this tenant.</div>
            @endif

            <div class="flex gap-2 flex-wrap border-b border-brand-border">
                @foreach (['overview' => 'Overview', 'payments' => 'Payments', 'arrears' => 'Arrears', 'complaints' => 'Complaints', 'documents' => 'Documents', 'activity' => 'Activity'] as $value => $label)
                    <button wire:click="$set('tab', '{{ $value }}')" class="px-3 py-2 text-sm border-b-2 {{ $tab === $value ? 'border-navy text-navy font-medium' : 'border-transparent text-brand-slate hover:text-charcoal' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'overview')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 grid grid-cols-2 gap-6 text-sm">
                    <div>
                        <p class="text-brand-slate">ID / Passport Number</p>
                        <p class="text-charcoal font-medium">{{ $tenant->id_number ?: '—' }}</p>
                    </div>
                    <div>
                        <p class="text-brand-slate">Rent Due Day</p>
                        <p class="text-charcoal font-medium">{{ \Illuminate\Support\Number::ordinal($tenant->payment_due_day) }}</p>
                    </div>
                    <div>
                        <p class="text-brand-slate">Emergency Contact</p>
                        <p class="text-charcoal font-medium">{{ $tenant->emergency_contact_name ?: '—' }} {{ $tenant->emergency_contact_phone ? '('.$tenant->emergency_contact_phone.')' : '' }}</p>
                    </div>
                    <div>
                        <p class="text-brand-slate">Notes</p>
                        <p class="text-charcoal">{{ $tenant->notes ?: '—' }}</p>
                    </div>
                </div>
            @elseif ($tab === 'payments')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Date</th>
                                <th class="text-left px-5 py-2 font-medium">Method</th>
                                <th class="text-left px-5 py-2 font-medium">Reference</th>
                                <th class="text-right px-5 py-2 font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($payments as $payment)
                                <tr>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $payment->payment_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ ucfirst($payment->method) }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $payment->reference_number ?? '—' }}</td>
                                    <td class="px-5 py-2.5 text-right text-charcoal">KSh {{ number_format($payment->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No payments recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @elseif ($tab === 'arrears')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Period</th>
                                <th class="text-left px-5 py-2 font-medium">Unit</th>
                                <th class="text-right px-5 py-2 font-medium">Balance</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($rentCharges as $charge)
                                <tr>
                                    <td class="px-5 py-2.5 text-charcoal">{{ \Carbon\Carbon::create()->month($charge->period_month)->format('M') }} {{ $charge->period_year }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $charge->tenancy->house->house_number }}</td>
                                    <td class="px-5 py-2.5 text-right text-danger">KSh {{ number_format($charge->amount_expected - $charge->amount_paid) }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$charge->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No outstanding balances.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @elseif ($tab === 'complaints')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Subject</th>
                                <th class="text-left px-5 py-2 font-medium">Priority</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($complaints as $complaint)
                                <tr>
                                    <td class="px-5 py-2.5 text-charcoal">{{ $complaint->subject }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$complaint->priority" /></td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$complaint->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-6 text-center text-gray-400">No complaints logged.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @elseif ($tab === 'documents')
                <livewire:documents.manager :model="$tenant" :key="'docs-tenant-'.$tenant->id" />
            @elseif ($tab === 'activity')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Unit</th>
                                <th class="text-left px-5 py-2 font-medium">Move In</th>
                                <th class="text-left px-5 py-2 font-medium">Move Out</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($tenancies as $tenancy)
                                <tr>
                                    <td class="px-5 py-2.5 text-charcoal">
                                        <a href="{{ route('manage.tenancies.show', $tenancy) }}" wire:navigate class="hover:underline">{{ $tenancy->house->block->property->name }} — {{ $tenancy->house->house_number }}</a>
                                    </td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $tenancy->move_in_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $tenancy->move_out_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$tenancy->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No tenancy history.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
