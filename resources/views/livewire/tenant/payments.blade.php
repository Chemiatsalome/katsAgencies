    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Payment History</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Date</th>
                                    <th class="text-right px-5 py-2 font-medium">Amount</th>
                                    <th class="text-left px-5 py-2 font-medium">Method</th>
                                    <th class="text-left px-5 py-2 font-medium">Reference</th>
                                    <th class="text-left px-5 py-2 font-medium">Rent Period</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($payments as $payment)
                                    <tr wire:key="payment-{{ $payment->id }}">
                                        <td class="px-5 py-3 text-charcoal">{{ $payment->payment_date->format('d M Y') }}</td>
                                        <td class="px-5 py-3 text-right text-charcoal font-medium">KSh {{ number_format($payment->amount, 2) }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $payment->isOpeningBalance() ? 'Historical balance (not a transaction)' : ucfirst($payment->method) }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $payment->reference_number ?? '—' }}</td>
                                        <td class="px-5 py-3 text-brand-slate">
                                            @if ($payment->rentCharge)
                                                {{ \Carbon\Carbon::create()->month($payment->rentCharge->period_month)->format('F') }} {{ $payment->rentCharge->period_year }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-5 py-3">
                                            <x-status-badge :status="$payment->status" />
                                            {{-- Phase 5b (P5-H-01): the same confirmation/dispute detail already
                                                 shown inline on My Rent (rent.blade.php) and My Deposit — a bare
                                                 "Disputed" badge with no reason, or "Awaiting Landlord
                                                 Confirmation" with no indication anything is actually happening,
                                                 left a tenant with no way to act on either state from this page. --}}
                                            @if ($payment->isDisputed())
                                                <p class="text-xs text-danger mt-1 max-w-xs">{{ $payment->dispute_reason ? "\"{$payment->dispute_reason}\"" : 'Your landlord has disputed this payment.' }}</p>
                                            @elseif ($payment->needsLandlordConfirmation())
                                                <p class="text-xs text-brand-slate mt-1">Awaiting your landlord's confirmation.</p>
                                            @elseif ($payment->confirmed_at)
                                                <p class="text-xs text-brand-slate mt-1">Confirmed {{ $payment->confirmed_at->format('d M Y') }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-5 py-6 text-center text-gray-400">No payments recorded yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <x-per-page-select :paginator="$payments" />
            </div>
        </div>
    </div>
