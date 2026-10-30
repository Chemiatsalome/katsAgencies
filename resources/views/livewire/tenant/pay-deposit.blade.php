    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Pay Deposit</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-md mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">

                    @if (! $transaction)
                        @if ($outstanding <= 0)
                            <div class="rounded-md bg-teal/10 border border-teal/30 text-charcoal text-sm px-4 py-3">
                                Your security deposit is already fully paid. You can still make an additional payment below if you'd like.
                            </div>
                        @else
                            <div class="rounded-md bg-warning/10 border border-warning/30 text-charcoal text-sm px-4 py-3">
                                KSh {{ number_format($outstanding, 2) }} of your security deposit is still outstanding.
                                This is separate from rent.
                            </div>
                        @endif

                        <form wire:submit="pay" class="space-y-4">
                            <div>
                                <x-input-label for="phone" value="M-Pesa Phone Number" />
                                <x-text-input id="phone" type="text" wire:model="phone" placeholder="07XXXXXXXX" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="amount" value="Amount (KSh)" />
                                <x-text-input id="amount" type="number" step="0.01" wire:model="amount" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                                <p class="text-xs text-brand-slate mt-1">Defaults to the full outstanding amount, but you can pay a partial amount instead.</p>
                            </div>

                            <x-primary-button type="submit" class="w-full justify-center" wire:loading.attr="disabled" wire:target="pay">
                                <span wire:loading.remove wire:target="pay">Pay Now</span>
                                <span wire:loading wire:target="pay">Sending prompt…</span>
                            </x-primary-button>
                        </form>

                        <a href="{{ route('tenant.deposit') }}" wire:navigate class="block text-center text-sm text-brand-slate hover:text-charcoal">Back to My Deposit</a>
                    @elseif ($transaction->status === 'pending')
                        <div wire:poll.3s="checkStatus" class="text-center space-y-3 py-4">
                            <div class="mx-auto h-10 w-10 border-4 border-teal border-t-transparent rounded-full animate-spin"></div>
                            <p class="text-sm font-medium text-charcoal">Check your phone</p>
                            <p class="text-sm text-brand-slate">Enter your M-Pesa PIN to complete the deposit payment of KSh {{ number_format($transaction->amount, 2) }}.</p>
                        </div>
                    @elseif ($transaction->status === 'success')
                        <div class="text-center space-y-3 py-4">
                            <div class="mx-auto h-10 w-10 rounded-full bg-green-50 text-green-600 flex items-center justify-center"><x-nav-icon name="check" class="h-5 w-5" /></div>
                            <p class="text-sm font-medium text-charcoal">Payment received</p>
                            <p class="text-sm text-brand-slate">
                                KSh {{ number_format($transaction->amount, 2) }} &middot; Receipt {{ $transaction->mpesa_receipt_number }}
                            </p>
                            <a href="{{ route('tenant.deposit') }}" wire:navigate class="inline-block text-sm text-teal hover:underline">View My Deposit</a>
                        </div>
                    @else
                        <div class="text-center space-y-3 py-4">
                            <div class="mx-auto h-10 w-10 rounded-full bg-red-50 text-danger flex items-center justify-center"><x-nav-icon name="x" class="h-5 w-5" /></div>
                            <p class="text-sm font-medium text-charcoal">
                                @if ($transaction->status === 'cancelled')
                                    Payment cancelled
                                @elseif ($transaction->status === 'timeout')
                                    We didn't hear back in time
                                @else
                                    Payment failed
                                @endif
                            </p>
                            @if ($transaction->result_desc)
                                <p class="text-sm text-brand-slate">{{ $transaction->result_desc }}</p>
                            @endif
                            <button wire:click="tryAgain" class="text-sm text-teal hover:underline">Try Again</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
