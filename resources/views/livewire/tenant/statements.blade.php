    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Statements &amp; Receipts</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (! $tenancy)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        No tenancy is linked to your account yet.
                    </div>
                @else
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-charcoal">Full Rent Statement</p>
                            <p class="text-xs text-brand-slate">Complete rent and payment history for your current tenancy.</p>
                        </div>
                        <button wire:click="downloadStatement" class="px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                            Download Statement
                        </button>
                    </div>

                    <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                        <div class="px-5 py-4 border-b border-brand-border">
                            <h3 class="text-sm font-semibold text-brand-slate">Payment Receipts</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-brand-slate">
                                    <tr>
                                        <th class="text-left px-5 py-2 font-medium">Date</th>
                                        <th class="text-right px-5 py-2 font-medium">Amount</th>
                                        <th class="text-left px-5 py-2 font-medium">Method</th>
                                        <th class="px-5 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($payments as $payment)
                                        <tr wire:key="receipt-{{ $payment->id }}">
                                            <td class="px-5 py-2 text-brand-slate">{{ $payment->payment_date->format('d M Y') }}</td>
                                            <td class="px-5 py-2 text-right text-charcoal">KSh {{ number_format($payment->amount, 2) }}</td>
                                            <td class="px-5 py-2 text-brand-slate">{{ ucfirst($payment->method) }}</td>
                                            <td class="px-5 py-2 text-right">
                                                <button wire:click="downloadReceipt({{ $payment->id }})" class="text-teal hover:underline text-xs font-medium">
                                                    Download Receipt
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-5 py-6 text-center text-gray-400">No payments recorded yet.</td>
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
