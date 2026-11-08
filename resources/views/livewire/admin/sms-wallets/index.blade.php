    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">SMS Wallets</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if ($totalNeedsReconciliation > 0)
                <div class="bg-warning/10 border border-warning/30 text-charcoal rounded-lg px-4 py-3 text-sm">
                    <span class="font-medium">{{ $totalNeedsReconciliation }}</span>
                    SMS {{ $totalNeedsReconciliation === 1 ? 'message needs' : 'messages need' }} manual reconciliation —
                    either Africa's Talking never confirmed whether a submission went through, or it was confirmed sent
                    but couldn't be billed automatically. Nothing here was adjusted automatically; check the provider's
                    own dashboard/logs for each message before deciding whether it was actually sent.
                </div>
            @endif

            <p class="text-sm text-brand-slate max-w-2xl">
                SMS rent reminders are an optional, self-serve add-on — a landlord tops up their own prepaid balance
                via Kats' own M-Pesa paybill, charged at
                <span class="font-medium text-charcoal">KSh {{ number_format($rate, 2) }} per message</span>
                (set in <a href="{{ route('admin.settings.index', ['tab' => 'payments']) }}" wire:navigate class="text-teal hover:underline">Settings → Payments</a>).
                This view is read-only — a balance only ever changes via a real top-up or a real SMS sent.
            </p>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Landlord</th>
                            <th class="text-left px-5 py-2 font-medium">SMS Reminders</th>
                            <th class="text-right px-5 py-2 font-medium">Balance</th>
                            <th class="text-right px-5 py-2 font-medium">Topped Up (All Time)</th>
                            <th class="text-right px-5 py-2 font-medium">Messages Sent</th>
                            <th class="text-left px-5 py-2 font-medium">Last Activity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($landlords as $row)
                            <tr>
                                <td class="px-5 py-2.5 text-charcoal font-medium">{{ $row->landlord->name }}</td>
                                <td class="px-5 py-2.5">
                                    @if ($row->smsEnabled)
                                        <x-status-badge status="active" />
                                    @else
                                        <span class="text-xs text-brand-slate">Not enabled</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-right font-medium {{ $row->smsEnabled && $row->balance < $rate ? 'text-warning' : 'text-charcoal' }}">
                                    KSh {{ number_format($row->balance, 2) }}
                                </td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($row->totalToppedUp, 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ number_format($row->messagesSent) }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $row->lastActivity?->format('d M Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>

            {{ $landlords->links() }}

            @if ($reconciliationRows->isNotEmpty())
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="px-5 py-3 border-b border-brand-border">
                        <h3 class="font-medium text-charcoal text-sm">Needs Reconciliation</h3>
                        <p class="text-xs text-brand-slate mt-0.5">
                            "Confirmed sent"/"Confirmed not sent" require evidence (e.g. the provider's own dashboard, or
                            the tenant confirming receipt) — never a guess, and resolving as sent never resends the
                            message, it only lets the normal wallet billing catch up.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Sent</th>
                                    <th class="text-left px-5 py-2 font-medium">To</th>
                                    <th class="text-left px-5 py-2 font-medium">Delivery</th>
                                    <th class="text-left px-5 py-2 font-medium">Billing</th>
                                    <th class="text-left px-5 py-2 font-medium">Resolution</th>
                                    <th class="text-right px-5 py-2 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($reconciliationRows as $row)
                                    <tr>
                                        <td class="px-5 py-2.5 text-brand-slate">{{ $row->created_at->format('d M Y H:i') }}</td>
                                        <td class="px-5 py-2.5 text-charcoal">{{ $row->notifiable?->name ?? "#{$row->notifiable_id}" }}</td>
                                        <td class="px-5 py-2.5">
                                            <span class="text-xs {{ $row->delivery_status === 'submission_unknown' ? 'text-warning' : 'text-brand-slate' }}">
                                                {{ str($row->delivery_status)->replace('_', ' ') }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-2.5"><span class="text-xs text-brand-slate">{{ str($row->billing_status)->replace('_', ' ') }}</span></td>
                                        <td class="px-5 py-2.5 text-xs text-brand-slate max-w-xs">
                                            @if ($row->reconciled_at)
                                                {{ $row->reconciliation_note }}
                                                <span class="block text-brand-slate/70">— {{ $row->reconciledBy?->name }}, {{ $row->reconciled_at->format('d M Y H:i') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                            @if ($row->delivery_status === 'submission_unknown')
                                                <button wire:click="startResolve({{ $row->id }}, 'confirmed_sent')" class="text-xs text-teal hover:underline">Confirmed sent</button>
                                                <button wire:click="startResolve({{ $row->id }}, 'confirmed_not_sent')" class="text-xs text-danger hover:underline ml-2">Confirmed not sent</button>
                                            @else
                                                <span class="text-xs text-brand-slate">Already billed/discrepancy recorded</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($totalNeedsReconciliation > 50)
                        <div class="px-5 py-2 border-t border-brand-border text-xs text-brand-slate">
                            Showing the 50 most recent of {{ $totalNeedsReconciliation }} unresolved submissions.
                            {{ $totalNeedsReconciliation - 50 }} older records are not shown.
                        </div>
                    @endif
                </div>
            @endif

            @if ($resolvingNotificationId)
                <div class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50" wire:click.self="cancelResolve">
                    <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-6 space-y-4">
                        <h3 class="font-semibold text-charcoal">
                            {{ $resolveAction === 'confirmed_sent' ? 'Confirm this message was actually sent' : 'Confirm this message was never sent' }}
                        </h3>
                        <p class="text-xs text-brand-slate">
                            This is a record of what you've independently verified — it never contacts Africa's Talking
                            or resends anything. @if ($resolveAction === 'confirmed_sent') Billing resumes automatically once you confirm. @endif
                        </p>

                        <div>
                            <x-input-label for="resolveNote" value="Evidence" />
                            <textarea id="resolveNote" wire:model="resolveNote" rows="3" class="mt-1 block w-full rounded-md border-brand-border text-sm" placeholder="e.g. checked Africa's Talking's own dashboard logs; tenant confirmed receipt by phone"></textarea>
                            <x-input-error :messages="$errors->get('resolveNote')" class="mt-1" />
                        </div>

                        <div class="flex items-center gap-3">
                            <x-primary-button wire:click="confirmResolve" wire:loading.attr="disabled" wire:target="confirmResolve">Confirm</x-primary-button>
                            <button type="button" wire:click="cancelResolve" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
