    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">SMS Wallet</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <p class="text-sm text-brand-slate max-w-2xl">
                SMS rent reminders are an optional add-on — each one costs Kats real money via our SMS provider, so
                top up a balance here to keep them sending. If your balance runs out, reminders simply fall back to
                in-app and email; nothing else is affected.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-xl">
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Wallet Balance</p>
                    <p class="text-lg font-semibold {{ $balance < $rate ? 'text-warning' : 'text-charcoal' }} mt-1">KSh {{ number_format($balance, 2) }}</p>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-4">
                    <p class="text-xs text-brand-slate">Cost per SMS</p>
                    <p class="text-lg font-semibold text-charcoal mt-1">KSh {{ number_format($rate, 2) }}</p>
                </div>
            </div>

            @if ($balance < $rate)
                <div class="rounded-md bg-warning/10 border border-warning/30 text-warning text-sm px-4 py-2 max-w-xl">
                    Your balance is below the cost of one SMS — reminders will send via in-app/email only until you top up.
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 max-w-md space-y-4">
                <h3 class="text-sm font-semibold text-charcoal">Top Up via M-Pesa</h3>

                @if (! $pendingTransaction)
                    <form wire:submit="topUp" class="space-y-4">
                        <div>
                            <x-input-label for="phone" value="M-Pesa Phone Number" />
                            <x-text-input id="phone" type="text" wire:model="phone" class="mt-1 block w-full" placeholder="07XXXXXXXX" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="amount" value="Amount (KSh)" />
                            <x-text-input id="amount" type="number" step="1" min="20" wire:model="amount" class="mt-1 block w-full" placeholder="e.g. 500" />
                            <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                            <p class="text-[11px] text-brand-slate mt-1">Minimum KSh 20.</p>
                        </div>
                        <x-primary-button type="submit">Top Up</x-primary-button>
                    </form>
                @elseif ($pendingTransaction->status === 'pending')
                    <div wire:poll.3s="checkStatus" class="text-center space-y-3 py-4">
                        <div class="mx-auto h-10 w-10 border-4 border-teal border-t-transparent rounded-full animate-spin"></div>
                        <p class="text-sm font-medium text-charcoal">Check your phone</p>
                        <p class="text-sm text-brand-slate">Enter your M-Pesa PIN to complete the top-up of KSh {{ number_format($pendingTransaction->amount, 2) }}.</p>
                    </div>
                @elseif ($pendingTransaction->status === 'success')
                    <div class="text-center space-y-3 py-4">
                        <div class="mx-auto h-10 w-10 rounded-full bg-green-50 text-green-600 flex items-center justify-center"><x-nav-icon name="check" class="h-5 w-5" /></div>
                        <p class="text-sm font-medium text-charcoal">Wallet topped up</p>
                        <p class="text-sm text-brand-slate">KSh {{ number_format($pendingTransaction->amount, 2) }} · Receipt {{ $pendingTransaction->mpesa_receipt_number }}</p>
                        <button wire:click="tryAgain" class="text-sm text-teal hover:underline">Top Up Again</button>
                    </div>
                @else
                    <div class="text-center space-y-3 py-4">
                        <div class="mx-auto h-10 w-10 rounded-full bg-red-50 text-danger flex items-center justify-center"><x-nav-icon name="x" class="h-5 w-5" /></div>
                        <p class="text-sm font-medium text-charcoal">
                            @if ($pendingTransaction->status === 'cancelled')
                                Top-up cancelled
                            @elseif ($pendingTransaction->status === 'timeout')
                                We didn't hear back in time
                            @else
                                Top-up failed
                            @endif
                        </p>
                        @if ($pendingTransaction->result_desc)
                            <p class="text-sm text-brand-slate">{{ $pendingTransaction->result_desc }}</p>
                        @endif
                        <button wire:click="tryAgain" class="text-sm text-teal hover:underline">Try Again</button>
                    </div>
                @endif
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden max-w-2xl">
                <div class="px-5 py-3 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Recent Activity</h3>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Date</th>
                            <th class="text-left px-5 py-2 font-medium">Description</th>
                            <th class="text-right px-5 py-2 font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($entries as $entry)
                            <tr>
                                <td class="px-5 py-2.5 text-brand-slate whitespace-nowrap">{{ $entry->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-5 py-2.5 text-charcoal">{{ $entry->description ?? ($entry->type === 'credit' ? 'Top-up' : 'SMS sent') }}</td>
                                <td class="px-5 py-2.5 text-right font-medium {{ $entry->type === 'credit' ? 'text-success' : 'text-charcoal' }}">
                                    {{ $entry->type === 'credit' ? '+' : '-' }}KSh {{ number_format($entry->amount, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-6 text-center text-brand-slate">No activity yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
