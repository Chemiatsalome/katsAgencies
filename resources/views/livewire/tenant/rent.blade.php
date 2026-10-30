    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Rent</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                @if (session('status'))
                    <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

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
                            @if ($advanceCreditAvailable > 0)
                                <p class="text-xs text-teal mt-1">
                                    You have KSh {{ number_format($advanceCreditAvailable, 2) }} in advance credit from a prior overpayment — already applied below, oldest charge first, to reduce what you still owe.
                                </p>
                            @endif
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
                                        <th class="text-right px-5 py-2 font-medium">Credit Applied</th>
                                        <th class="text-right px-5 py-2 font-medium">Net Due</th>
                                        <th class="text-left px-5 py-2 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($rentCharges as $charge)
                                        @php
                                            $chargePayments = $payments->where('rent_charge_id', $charge->id);
                                            $creditApplied = (float) ($creditAppliedByChargeId[$charge->id] ?? 0);
                                            $grossBalance = (float) $charge->amount_expected - (float) $charge->amount_paid;
                                            $netDue = max(0, $grossBalance - $creditApplied);
                                        @endphp
                                        <tr>
                                            <td class="px-5 py-2 text-charcoal">{{ \Carbon\Carbon::create()->month($charge->period_month)->format('F') }} {{ $charge->period_year }}</td>
                                            <td class="px-5 py-2 text-brand-slate">{{ $charge->due_date->format('d M Y') }}</td>
                                            <td class="px-5 py-2 text-right text-brand-slate">
                                                KSh {{ number_format($charge->amount_expected, 2) }}
                                                @if ($charge->late_fee_amount > 0)
                                                    <span class="block text-xs text-warning whitespace-nowrap">incl. KSh {{ number_format($charge->late_fee_amount, 2) }} late fee</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-2 text-right text-brand-slate">KSh {{ number_format($charge->amount_paid, 2) }}</td>
                                            <td class="px-5 py-2 text-right {{ $grossBalance > 0 ? 'text-danger' : 'text-brand-slate' }}">
                                                KSh {{ number_format($grossBalance, 2) }}
                                            </td>
                                            <td class="px-5 py-2 text-right {{ $creditApplied > 0 ? 'text-teal' : 'text-gray-400' }}">
                                                {{ $creditApplied > 0 ? 'KSh '.number_format($creditApplied, 2) : '—' }}
                                            </td>
                                            <td class="px-5 py-2 text-right {{ $netDue > 0 ? 'text-danger' : 'text-brand-slate' }} font-medium">
                                                KSh {{ number_format($netDue, 2) }}
                                                @if ($grossBalance > 0 && $netDue <= 0)
                                                    <span class="block text-[11px] text-teal font-normal">Covered by advance credit</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-2">
                                                <x-status-badge :status="$charge->status" />
                                                @if ($charge->late_fee_amount > 0)
                                                    @php $latestRequest = $charge->waiverRequests->sortByDesc('created_at')->first(); @endphp
                                                    @if ($latestRequest?->status === 'pending')
                                                        <span class="block mt-1"><x-status-badge status="pending" /></span>
                                                        <span class="block text-xs text-brand-slate">Waiver requested</span>
                                                    @else
                                                        @if ($latestRequest?->status === 'rejected')
                                                            <span class="block mt-1 text-xs text-danger">Previous request declined</span>
                                                            @if ($latestRequest->decision_note)
                                                                <span class="block text-xs text-brand-slate">"{{ $latestRequest->decision_note }}"</span>
                                                            @endif
                                                        @endif
                                                        <button wire:click="openWaiverForm({{ $charge->id }})" class="block mt-1 text-xs text-teal hover:underline">Request Waiver</button>
                                                    @endif

                                                    @if ($charge->waiverRequests->count() > 1)
                                                        <div x-data="{ open: false }">
                                                            <button @click="open = !open" class="block mt-1 text-xs text-brand-slate hover:underline" x-text="open ? 'Hide past requests' : 'Show past requests ({{ $charge->waiverRequests->count() }})'"></button>
                                                            <ul x-show="open" x-cloak class="text-xs text-brand-slate space-y-0.5 mt-1">
                                                                @foreach ($charge->waiverRequests->sortByDesc('created_at') as $past)
                                                                    <li>
                                                                        {{ $past->created_at->format('d M Y') }} —
                                                                        <span class="{{ $past->status === 'accepted' ? 'text-success' : ($past->status === 'rejected' ? 'text-danger' : '') }}">{{ ucfirst($past->status) }}</span>
                                                                        @if ($past->decision_note)
                                                                            — "{{ $past->decision_note }}"
                                                                        @endif
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>
                                        @if ($chargePayments->isNotEmpty())
                                            @php $manyPayments = $chargePayments->count() > 3; @endphp
                                            <tr x-data="{ open: false }">
                                                <td colspan="8" class="px-5 py-2 bg-offwhite text-xs text-brand-slate">
                                                    <div class="font-medium text-brand-slate mb-1">
                                                        Payments this period
                                                        <span class="text-gray-400 font-normal">({{ $chargePayments->count() }} · KSh {{ number_format($chargePayments->sum('amount'), 2) }} total)</span>
                                                        {{-- Many small payments against one period (e.g. several test
                                                             STK pushes) otherwise turn this section into one unreadable
                                                             wall of text — collapsed behind a toggle past a few, same
                                                             pattern as the waiver-history toggle above. --}}
                                                        @if ($manyPayments)
                                                            <button type="button" @click="open = !open" class="text-teal hover:underline font-medium ml-1" x-text="open ? 'Hide' : 'Show'"></button>
                                                        @endif
                                                    </div>
                                                    <ul @if ($manyPayments) x-show="open" x-cloak @endif class="space-y-0.5">
                                                        @foreach ($chargePayments as $p)
                                                            <li>
                                                                {{ $p->payment_date->format('d M Y') }} · KSh {{ number_format($p->amount, 2) }} · {{ $p->isOpeningBalance() ? 'historical balance' : ucfirst($p->method) }}
                                                                @if ($p->needsLandlordConfirmation())
                                                                    <span class="text-warning font-medium">(awaiting landlord confirmation)</span>
                                                                @elseif ($p->isDisputed())
                                                                    <span class="text-danger font-medium">(disputed{{ $p->dispute_reason ? ': "'.$p->dispute_reason.'"' : '' }})</span>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </td>
                                            </tr>
                                        @endif
                                    @empty
                                        <tr>
                                            <td colspan="8" class="px-5 py-6 text-center text-gray-400">No rent charges yet.</td>
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

        @if ($waiverChargeId)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="cancelWaiverForm">
                <form wire:submit="requestWaiver" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-md space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">Request a Late Fee Waiver</h3>
                    <p class="text-xs text-brand-slate">Let your landlord know why this fee should be waived — they'll be able to accept or decline your request.</p>
                    <textarea wire:model="waiverReason" rows="4" class="block w-full rounded-md border-brand-border" placeholder="e.g. I paid on time but the payment wasn't recorded"></textarea>
                    <x-input-error :messages="$errors->get('waiverReason')" class="mt-1" />
                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Send Request</x-primary-button>
                        <button type="button" wire:click="cancelWaiverForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
