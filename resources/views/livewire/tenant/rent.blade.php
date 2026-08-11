    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Rent</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                @if (! $tenancy)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        No tenancy is linked to your account yet.
                    </div>
                @else
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-brand-slate">Year</label>
                        <select wire:model.live="year" class="rounded-md border-brand-border text-sm">
                            @foreach ($availableYears as $y)
                                <option value="{{ $y }}">{{ $y }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                        <div class="px-5 py-4 border-b border-brand-border">
                            <h3 class="text-sm font-semibold text-brand-slate">{{ $year }} Rent Charges</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-brand-slate">
                                    <tr>
                                        <th class="text-left px-5 py-2 font-medium">Period</th>
                                        <th class="text-left px-5 py-2 font-medium">Due Date</th>
                                        <th class="text-right px-5 py-2 font-medium">Expected</th>
                                        <th class="text-right px-5 py-2 font-medium">Paid</th>
                                        <th class="text-right px-5 py-2 font-medium">Balance</th>
                                        <th class="text-left px-5 py-2 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($rentCharges as $charge)
                                        @php $chargePayments = $payments->where('rent_charge_id', $charge->id); @endphp
                                        <tr>
                                            <td class="px-5 py-2 text-charcoal">{{ \Carbon\Carbon::create()->month($charge->period_month)->format('F') }} {{ $charge->period_year }}</td>
                                            <td class="px-5 py-2 text-brand-slate">{{ $charge->due_date->format('d M Y') }}</td>
                                            <td class="px-5 py-2 text-right text-brand-slate">KSh {{ number_format($charge->amount_expected, 2) }}</td>
                                            <td class="px-5 py-2 text-right text-brand-slate">KSh {{ number_format($charge->amount_paid, 2) }}</td>
                                            <td class="px-5 py-2 text-right {{ $charge->amount_expected > $charge->amount_paid ? 'text-danger' : 'text-brand-slate' }}">
                                                KSh {{ number_format($charge->amount_expected - $charge->amount_paid, 2) }}
                                            </td>
                                            <td class="px-5 py-2"><x-status-badge :status="$charge->status" /></td>
                                        </tr>
                                        @if ($chargePayments->isNotEmpty())
                                            <tr>
                                                <td colspan="6" class="px-5 py-2 bg-offwhite text-xs text-brand-slate">
                                                    Payments this period:
                                                    @foreach ($chargePayments as $p)
                                                        {{ $p->payment_date->format('d M Y') }} · KSh {{ number_format($p->amount, 2) }} · {{ ucfirst($p->method) }}@if (! $loop->last), @endif
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endif
                                    @empty
                                        <tr>
                                            <td colspan="6" class="px-5 py-6 text-center text-gray-400">No rent charges yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <a href="{{ route('tenant.payments') }}" wire:navigate class="inline-block text-sm text-teal hover:underline">
                        View full payment history →
                    </a>
                @endif

            </div>
        </div>
    </div>
