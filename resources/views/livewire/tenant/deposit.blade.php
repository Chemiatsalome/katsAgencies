    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Deposit</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                @if (! $tenancy)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        No tenancy is linked to your account yet.
                    </div>
                @else
                    @if (session('status'))
                        <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="bg-red-50 border border-red-200 text-danger text-sm rounded-md px-4 py-2">{{ session('error') }}</div>
                    @endif
                    @if ($outstanding > 0)
                        <div class="rounded-md bg-warning/10 border border-warning/30 text-charcoal text-sm px-4 py-3 space-y-2">
                            <p class="font-medium">Your security deposit isn't fully paid yet, KSh {{ number_format($outstanding, 2) }} outstanding.</p>
                            @if ($tenancy->deposit_request_sent_at)
                                <p class="text-xs text-brand-slate">Your landlord requested this on {{ $tenancy->deposit_request_sent_at->format('d M Y') }}.</p>
                            @endif
                            <p class="text-xs text-brand-slate">This is separate from your rent, being paid up on rent doesn't cover this.</p>
                            <a href="{{ route('tenant.deposit.pay') }}" wire:navigate class="inline-block text-sm font-medium text-teal hover:underline">Pay via M-Pesa</a>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <x-stat-tile label="Required Deposit" value="KSh {{ number_format($required, 2) }}" />
                        <x-stat-tile label="Total Paid" value="KSh {{ number_format($paid, 2) }}" tone="good" />
                        <x-stat-tile label="Outstanding" value="KSh {{ number_format($outstanding, 2) }}" tone="{{ $outstanding > 0 ? 'warning' : 'neutral' }}" />
                        <x-stat-tile label="Currently Held" value="KSh {{ number_format($held, 2) }}" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <x-stat-tile label="Total Deductions" value="KSh {{ number_format($deducted, 2) }}" tone="{{ $deducted > 0 ? 'critical' : 'neutral' }}" />
                        <x-stat-tile label="Total Refunded" value="KSh {{ number_format($refunded, 2) }}" />
                    </div>

                    <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                        <div class="px-5 py-4 border-b border-brand-border">
                            <h3 class="text-sm font-semibold text-brand-slate">Deductions</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-brand-slate">
                                    <tr>
                                        <th class="text-left px-5 py-2 font-medium">Date</th>
                                        <th class="text-left px-5 py-2 font-medium">Reason</th>
                                        <th class="text-left px-5 py-2 font-medium">Evidence</th>
                                        <th class="text-right px-5 py-2 font-medium">Amount</th>
                                        <th class="text-right px-5 py-2 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($deductions as $deduction)
                                        <tr class="{{ $deduction->isVoided() ? 'opacity-50' : '' }}">
                                            <td class="px-5 py-2 text-brand-slate">{{ $deduction->deduction_date->format('d M Y') }}</td>
                                            <td class="px-5 py-2 text-charcoal">
                                                {{ $deduction->reason }}
                                                @if ($deduction->isVoided())
                                                    <span class="block text-[11px] text-brand-slate">Voided{{ $deduction->void_reason ? ': '.$deduction->void_reason : '' }}</span>
                                                @elseif ($deduction->isDisputePending())
                                                    <span class="block text-[11px] text-warning">Dispute pending: {{ $deduction->dispute_reason }}</span>
                                                @elseif ($deduction->disputed_at)
                                                    <span class="block text-[11px] text-brand-slate">Dispute resolved — deduction upheld</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-2">
                                                @forelse ($deduction->documents as $document)
                                                    <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="text-teal hover:underline text-xs">{{ $document->original_filename }}</a>
                                                @empty
                                                    <span class="text-xs text-gray-400">None</span>
                                                @endforelse
                                            </td>
                                            <td class="px-5 py-2 text-right {{ $deduction->isVoided() ? 'text-brand-slate line-through' : 'text-danger' }}">
                                                -KSh {{ number_format($deduction->amount, 2) }}
                                            </td>
                                            <td class="px-5 py-2 text-right whitespace-nowrap">
                                                @if (! $deduction->isVoided() && ! $deduction->isDisputePending())
                                                    <button wire:click="startDispute({{ $deduction->id }})" class="text-xs text-danger hover:underline">Dispute</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="px-5 py-6 text-center text-gray-400">No deductions recorded.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                        <div class="px-5 py-4 border-b border-brand-border">
                            <h3 class="text-sm font-semibold text-brand-slate">Payment &amp; Refund History</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-brand-slate">
                                    <tr>
                                        <th class="text-left px-5 py-2 font-medium">Date</th>
                                        <th class="text-left px-5 py-2 font-medium">Type</th>
                                        <th class="text-right px-5 py-2 font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($payments as $payment)
                                        <tr>
                                            <td class="px-5 py-2 text-brand-slate">{{ $payment->payment_date->format('d M Y') }}</td>
                                            <td class="px-5 py-2 text-charcoal">Deposit Payment ({{ ucfirst($payment->method) }})</td>
                                            <td class="px-5 py-2 text-right text-success">+KSh {{ number_format($payment->amount, 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-5 py-6 text-center text-gray-400">No deposit payments recorded yet.</td>
                                        </tr>
                                    @endforelse
                                    @foreach ($refunds as $refund)
                                        <tr>
                                            <td class="px-5 py-2 text-brand-slate">{{ $refund->refund_date->format('d M Y') }}</td>
                                            <td class="px-5 py-2 text-charcoal">Refund{{ $refund->notes ? ' ('.$refund->notes.')' : '' }}</td>
                                            <td class="px-5 py-2 text-right text-danger">-KSh {{ number_format($refund->amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    @if ($disputingDeductionId)
                        <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="cancelDispute">
                            <form wire:submit="confirmDispute" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-sm space-y-3">
                                <h3 class="text-sm font-semibold text-charcoal">Dispute This Deduction</h3>
                                <p class="text-xs text-brand-slate">
                                    Tell your landlord why you disagree. The deduction stays on record but won't count against your held deposit until this is resolved.
                                </p>
                                <div>
                                    <x-input-label for="dispute_reason" value="Reason" />
                                    <textarea id="dispute_reason" wire:model="dispute_reason" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                                    <x-input-error :messages="$errors->get('dispute_reason')" class="mt-1" />
                                </div>
                                <div class="flex items-center gap-3">
                                    <x-primary-button type="submit">Submit Dispute</x-primary-button>
                                    <button type="button" wire:click="cancelDispute" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                </div>
                            </form>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
