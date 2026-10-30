    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Payout Statement — {{ $landlord->name }}</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-md px-4 py-2">{{ session('error') }}</div>
            @endif

            <a href="{{ route('admin.disbursements.index') }}" wire:navigate class="text-sm text-teal hover:underline">&larr; All Disbursements</a>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Collected (M-Pesa)</p>
                    <p class="text-lg font-semibold text-charcoal mt-1">KSh {{ number_format($collected, 2) }}</p>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Disbursed</p>
                    <p class="text-lg font-semibold text-charcoal mt-1">KSh {{ number_format($disbursed, 2) }}</p>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Outstanding (Owed)</p>
                    <p class="text-lg font-semibold {{ $owed > 0 ? 'text-warning' : 'text-charcoal' }} mt-1">KSh {{ number_format($owed, 2) }}</p>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Awaiting Confirmation</p>
                    <p class="text-lg font-semibold text-warning mt-1">KSh {{ number_format($awaitingTotal, 2) }}</p>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Disputed</p>
                    <p class="text-lg font-semibold text-danger mt-1">KSh {{ number_format($disputedTotal, 2) }}</p>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Date</th>
                            <th class="text-left px-5 py-2 font-medium">Period</th>
                            <th class="text-right px-5 py-2 font-medium">Amount</th>
                            <th class="text-left px-5 py-2 font-medium">Destination</th>
                            <th class="text-left px-5 py-2 font-medium">Reference</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-left px-5 py-2 font-medium">Recorded By</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($disbursements as $disbursement)
                            <tr>
                                <td class="px-5 py-2.5 text-brand-slate whitespace-nowrap">{{ $disbursement->disbursed_at->format('d M Y') }}</td>
                                <td class="px-5 py-2.5 text-brand-slate whitespace-nowrap">
                                    @if ($disbursement->payout_period_start)
                                        {{ $disbursement->payout_period_start->format('d M') }} &ndash; {{ $disbursement->payout_period_end?->format('d M Y') }}
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-right font-medium text-charcoal">KSh {{ number_format($disbursement->amount, 2) }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $disbursement->destination_type ? ucfirst($disbursement->destination_type).' '.$disbursement->destination_shortcode : '—' }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $disbursement->reference ?? '—' }}</td>
                                <td class="px-5 py-2.5">
                                    <x-status-badge :status="$disbursement->status" />
                                    @if ($disbursement->isDisputed() && $disbursement->dispute_reason)
                                        <span class="block text-xs text-brand-slate mt-0.5">"{{ $disbursement->dispute_reason }}"</span>
                                    @endif
                                    @if (in_array($disbursement->status, ['reversed', 'failed']) && $disbursement->reversal_reason)
                                        <span class="block text-xs text-brand-slate mt-0.5">{{ $disbursement->reversal_reason }}</span>
                                    @endif
                                    @foreach ($disbursement->documents as $document)
                                        <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="block text-teal hover:underline text-xs mt-0.5">{{ $document->original_filename }}</a>
                                    @endforeach
                                </td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $disbursement->recordedBy?->name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                    @if (in_array($disbursement->status, ['awaiting_landlord_confirmation', 'confirmed', 'disputed']))
                                        <button
                                            x-data
                                            @click="let reason = prompt('Reason for reversing this disbursement (money goes back into what is owed):'); if (! reason) return; $wire.reverseDisbursement({{ $disbursement->id }}, reason)"
                                            class="text-xs font-medium text-danger hover:underline"
                                        >Reverse</button>
                                        <button
                                            x-data
                                            @click="let reason = prompt('Reason the transfer failed:'); if (! reason) return; $wire.markFailed({{ $disbursement->id }}, reason)"
                                            class="text-xs font-medium text-brand-slate hover:text-charcoal ml-2"
                                        >Mark Failed</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-6 text-center text-brand-slate">No disbursements recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
