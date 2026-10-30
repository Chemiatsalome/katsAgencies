    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Payouts</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-md px-4 py-2">{{ session('error') }}</div>
            @endif

            <p class="text-sm text-brand-slate max-w-2xl">
                Rent tenants pay via M-Pesa into Kats Agencies' own paybill is held here until paid out to you — the
                transfer itself always happens outside this system (M-Pesa or bank). When Kats records a payout below,
                confirm it once you've actually received it, or report a problem if it's missing, short, or wrong.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Collected on Your Behalf (All Time)</p>
                    <p class="text-lg font-semibold text-charcoal mt-1">KSh {{ number_format($collected, 2) }}</p>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Paid Out to You (All Time)</p>
                    <p class="text-lg font-semibold text-charcoal mt-1">KSh {{ number_format($disbursed, 2) }}</p>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Currently Held, Not Yet Paid Out</p>
                    <p class="text-lg font-semibold {{ $owed > 0 ? 'text-warning' : 'text-charcoal' }} mt-1">KSh {{ number_format($owed, 2) }}</p>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Date</th>
                            <th class="text-right px-5 py-2 font-medium">Amount</th>
                            <th class="text-left px-5 py-2 font-medium">Destination</th>
                            <th class="text-left px-5 py-2 font-medium">Reference</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($disbursements as $disbursement)
                            <tr>
                                <td class="px-5 py-2.5 text-brand-slate whitespace-nowrap">{{ $disbursement->disbursed_at->format('d M Y') }}</td>
                                <td class="px-5 py-2.5 text-right font-medium text-charcoal">KSh {{ number_format($disbursement->amount, 2) }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $disbursement->destination_type ? ucfirst($disbursement->destination_type).' '.$disbursement->destination_shortcode : '—' }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $disbursement->reference ?? '—' }}</td>
                                <td class="px-5 py-2.5">
                                    <x-status-badge :status="$disbursement->status" />
                                    @if ($disbursement->isDisputed() && $disbursement->dispute_reason)
                                        <span class="block text-xs text-brand-slate mt-0.5">"{{ $disbursement->dispute_reason }}"</span>
                                    @endif
                                    @foreach ($disbursement->documents as $document)
                                        <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="block text-teal hover:underline text-xs mt-0.5">{{ $document->original_filename }}</a>
                                    @endforeach
                                </td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                    @if ($disbursement->needsLandlordConfirmation())
                                        <button wire:click="confirm({{ $disbursement->id }})" wire:confirm="Confirm you received this KSh {{ number_format($disbursement->amount, 2) }} payout?" class="text-xs font-medium text-success hover:underline">Confirm Receipt</button>
                                        <button
                                            x-data
                                            @click="let reason = prompt('What is wrong with this payout? (missing, wrong amount, wrong details)'); if (! reason) return; $wire.dispute({{ $disbursement->id }}, reason)"
                                            class="text-xs font-medium text-danger hover:underline ml-2"
                                        >Report a Problem</button>
                                    @elseif ($disbursement->isConfirmed())
                                        <button
                                            x-data
                                            @click="let reason = prompt('What is wrong with this payout?'); if (! reason) return; $wire.dispute({{ $disbursement->id }}, reason)"
                                            class="text-xs font-medium text-danger hover:underline"
                                        >Report a Problem</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-center text-brand-slate">No payouts recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
