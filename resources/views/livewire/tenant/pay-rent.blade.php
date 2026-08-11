    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Pay Rent</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-md mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">

                    @if (! $transaction)
                        <form wire:submit="pay" class="space-y-4">
                            <div>
                                <x-input-label for="phone" value="M-Pesa Phone Number" />
                                <x-text-input id="phone" type="text" wire:model="phone" placeholder="07XXXXXXXX" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label value="How much are you paying?" />
                                <div class="mt-1 space-y-2">
                                    @if ($fullBalance > 0)
                                        <label class="flex items-center justify-between gap-3 p-3 rounded-md border border-brand-border cursor-pointer {{ $paymentOption === 'full' ? 'border-teal ring-1 ring-teal' : '' }}">
                                            <span class="flex items-center gap-2 text-sm">
                                                <input type="radio" wire:model.live="paymentOption" value="full" class="border-brand-border">
                                                Full outstanding balance
                                            </span>
                                            <span class="text-sm font-medium text-charcoal">KSh {{ number_format($fullBalance, 2) }}</span>
                                        </label>
                                    @endif

                                    @if ($monthAmount > 0 && $monthAmount != $fullBalance)
                                        <label class="flex items-center justify-between gap-3 p-3 rounded-md border border-brand-border cursor-pointer {{ $paymentOption === 'month' ? 'border-teal ring-1 ring-teal' : '' }}">
                                            <span class="flex items-center gap-2 text-sm">
                                                <input type="radio" wire:model.live="paymentOption" value="month" class="border-brand-border">
                                                This month's rent
                                            </span>
                                            <span class="text-sm font-medium text-charcoal">KSh {{ number_format($monthAmount, 2) }}</span>
                                        </label>
                                    @endif

                                    <label class="flex items-center gap-2 p-3 rounded-md border border-brand-border cursor-pointer {{ $paymentOption === 'custom' ? 'border-teal ring-1 ring-teal' : '' }}">
                                        <input type="radio" wire:model.live="paymentOption" value="custom" class="border-brand-border">
                                        <span class="text-sm">Partial / custom amount</span>
                                    </label>
                                </div>
                            </div>

                            <div>
                                <x-input-label for="amount" value="Amount (KSh)" />
                                <x-text-input id="amount" type="number" step="0.01" wire:model="amount" class="mt-1 block w-full" :disabled="$paymentOption !== 'custom'" />
                                <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                                @if ($fullBalance > $monthAmount && $monthAmount > 0)
                                    <p class="text-xs text-brand-slate mt-1">
                                        Payments are applied to your oldest unpaid rent first. If this covers more than one month, each period is credited in order.
                                    </p>
                                @endif
                            </div>

                            <x-primary-button type="submit" class="w-full justify-center" wire:loading.attr="disabled" wire:target="pay">
                                <span wire:loading.remove wire:target="pay">Pay Now</span>
                                <span wire:loading wire:target="pay">Sending prompt…</span>
                            </x-primary-button>
                        </form>
                    @elseif ($transaction->status === 'pending')
                        <div wire:poll.3s="checkStatus" class="text-center space-y-3 py-4">
                            <div class="mx-auto h-10 w-10 border-4 border-teal border-t-transparent rounded-full animate-spin"></div>
                            <p class="text-sm font-medium text-charcoal">Check your phone</p>
                            <p class="text-sm text-brand-slate">Enter your M-Pesa PIN to complete the payment of KSh {{ number_format($transaction->amount, 2) }}.</p>
                        </div>
                    @elseif ($transaction->status === 'success')
                        <div class="text-center space-y-3 py-4">
                            <div class="mx-auto h-10 w-10 rounded-full bg-green-50 text-green-600 flex items-center justify-center text-xl">✓</div>
                            <p class="text-sm font-medium text-charcoal">Payment received</p>
                            <p class="text-sm text-brand-slate">
                                KSh {{ number_format($transaction->amount, 2) }} · Receipt {{ $transaction->mpesa_receipt_number }}
                            </p>
                            <a href="{{ route('tenant.dashboard') }}" wire:navigate class="inline-block text-sm text-teal hover:underline">Back to Dashboard</a>
                        </div>
                    @else
                        <div class="text-center space-y-3 py-4">
                            <div class="mx-auto h-10 w-10 rounded-full bg-red-50 text-danger flex items-center justify-center text-xl">✕</div>
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
