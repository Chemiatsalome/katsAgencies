    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Pay Deposit</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-md mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">

                    @if ($unresolvedTransactions->count() > 1)
                        <div class="space-y-3">
                            <p class="text-sm font-medium text-charcoal">You have {{ $unresolvedTransactions->count() }} deposit payment attempts that haven't resolved yet.</p>
                            @foreach ($unresolvedTransactions as $u)
                                <div class="rounded-md border border-brand-border p-3 text-sm space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium text-charcoal">KSh {{ number_format($u->amount, 2) }}</span>
                                        <span class="text-xs text-brand-slate">{{ $u->created_at->format('d M Y H:i') }}</span>
                                    </div>
                                    @if ($u->cancellationRequested())
                                        <p class="text-xs text-brand-slate">We've been notified and are looking into this.</p>
                                    @elseif ($u->needsManualVerification())
                                        <p class="text-xs text-warning">Needs verification — please don't pay again for this one.</p>
                                    @elseif ($u->created_at->lt(now()->subMinutes(10)))
                                        <button wire:click="requestCancellation({{ $u->id }})" class="text-xs text-teal hover:underline">Ask us to look into this</button>
                                    @else
                                        <p class="text-xs text-brand-slate">Still waiting to hear back…</p>
                                    @endif
                                </div>
                            @endforeach
                            @if (session('status'))
                                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
                            @endif
                            <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                        </div>
                    @elseif (! $transaction)
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

                        @if (! $mpesaAvailable)
                            <div class="rounded-md bg-navy-light border border-navy/20 text-charcoal text-sm px-4 py-3">
                                <p class="font-medium">Your landlord hasn't set up online M-Pesa payment for this property.</p>
                                <p class="mt-1">
                                    You can still use this page as a record of your deposit — pay your landlord however you normally do (cash, bank, their own M-Pesa till or paybill), then tap
                                    <span class="font-medium">"Already paid another way"</span> below to log it. Once your landlord confirms it, it'll reflect in your deposit balance here.
                                </p>
                            </div>
                        @endif

                        <form wire:submit="pay" class="space-y-4 {{ $mpesaAvailable ? '' : 'opacity-50 pointer-events-none' }}">
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

                        @if (session('status'))
                            <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
                        @endif

                        @if (! $showOfflineForm)
                            <button wire:click="openOfflineForm" class="w-full text-center text-sm text-teal hover:underline border-t border-brand-border pt-4">
                                Already paid another way (bank, cash, landlord's till)? Tell us.
                            </button>
                        @else
                            <form wire:submit="submitOfflinePayment" class="border-t border-brand-border pt-4 space-y-4">
                                <p class="text-xs text-brand-slate">
                                    This submits a claim for your landlord to confirm — it won't reflect in your deposit balance until they do.
                                </p>
                                <div>
                                    <x-input-label for="offline_method" value="How did you pay?" />
                                    <select id="offline_method" wire:model="offline_method" class="mt-1 block w-full rounded-md border-brand-border">
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cash">Cash</option>
                                        <option value="till">Landlord's M-Pesa Till</option>
                                        <option value="paybill">Landlord's M-Pesa Paybill</option>
                                        <option value="other">Other</option>
                                    </select>
                                    <x-input-error :messages="$errors->get('offline_method')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="offline_amount" value="Amount (KSh)" />
                                    <x-text-input id="offline_amount" type="number" step="0.01" wire:model="offline_amount" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('offline_amount')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="offline_payment_date" value="Date Paid" />
                                    <x-text-input id="offline_payment_date" type="date" wire:model="offline_payment_date" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('offline_payment_date')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="offline_reference" value="Reference / Confirmation Code (optional)" />
                                    <x-text-input id="offline_reference" type="text" wire:model="offline_reference" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('offline_reference')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="offline_evidence" value="Evidence (optional — receipt, screenshot, slip)" />
                                    <input type="file" id="offline_evidence" wire:model="offline_evidence" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm">
                                    <div wire:loading wire:target="offline_evidence" class="text-xs text-brand-slate mt-1">Uploading…</div>
                                    <x-input-error :messages="$errors->get('offline_evidence')" class="mt-1" />
                                </div>
                                <div class="flex items-center gap-3">
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="submitOfflinePayment">
                                        <span wire:loading.remove wire:target="submitOfflinePayment">Submit for Confirmation</span>
                                        <span wire:loading wire:target="submitOfflinePayment">Submitting…</span>
                                    </x-primary-button>
                                    <button type="button" wire:click="cancelOfflineForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                </div>
                            </form>
                        @endif

                        <a href="{{ route('tenant.deposit') }}" wire:navigate class="block text-center text-sm text-brand-slate hover:text-charcoal">Back to My Deposit</a>
                    @elseif (in_array($transaction->status, ['pending', 'timeout']))
                        {{-- 'timeout' here is only ever a legacy row from before this fix
                             (see MpesaTransaction::isFinished()'s docblock) — treated exactly
                             like 'pending', never as a dead end. --}}
                        <div wire:poll.3s="checkStatus" class="text-center space-y-3 py-4">
                            @if ($transaction->cancellationRequested())
                                <div class="mx-auto h-10 w-10 rounded-full bg-navy-light text-navy flex items-center justify-center"><x-nav-icon name="warning" class="h-5 w-5" /></div>
                                <p class="text-sm font-medium text-charcoal">We're looking into this</p>
                                <p class="text-sm text-brand-slate">You asked us to check on this KSh {{ number_format($transaction->amount, 2) }} deposit payment — we'll update you once it's resolved.</p>
                            @elseif ($transaction->needsManualVerification())
                                <div class="mx-auto h-10 w-10 rounded-full bg-warning/10 text-warning flex items-center justify-center"><x-nav-icon name="warning" class="h-5 w-5" /></div>
                                <p class="text-sm font-medium text-charcoal">Needs verification</p>
                                <p class="text-sm text-brand-slate">We're confirming this KSh {{ number_format($transaction->amount, 2) }} deposit payment with your landlord — please don't pay again until it's resolved.</p>
                            @else
                                <div class="mx-auto h-10 w-10 border-4 border-teal border-t-transparent rounded-full animate-spin"></div>
                                <p class="text-sm font-medium text-charcoal">Check your phone</p>
                                <p class="text-sm text-brand-slate">Enter your M-Pesa PIN to complete the deposit payment of KSh {{ number_format($transaction->amount, 2) }}.</p>
                                @if ($transaction->created_at->lt(now()->subMinutes(10)))
                                    <p class="text-xs text-brand-slate">Taking longer than usual?</p>
                                    <button wire:click="requestCancellation({{ $transaction->id }})" class="text-sm text-teal hover:underline">Ask us to look into this</button>
                                @endif
                            @endif
                            @if (session('status'))
                                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
                            @endif
                            <x-input-error :messages="$errors->get('phone')" class="mt-1" />
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
                    @elseif ($transaction->status === 'failed' && (int) $transaction->result_code === 0)
                        {{-- Safaricom's own result code was 0 (the push was approved) — our
                             ledger still flagged this one (amount mismatch, or the same receipt
                             already recorded another way) rather than crediting it blindly. This
                             is never a plain decline, so it must never say "failed" or invite an
                             immediate retry that could double-charge the tenant. --}}
                        <div class="text-center space-y-3 py-4">
                            <div class="mx-auto h-10 w-10 rounded-full bg-warning/10 text-warning flex items-center justify-center"><x-nav-icon name="warning" class="h-5 w-5" /></div>
                            <p class="text-sm font-medium text-charcoal">Needs verification</p>
                            @if ($transaction->result_desc)
                                <p class="text-sm text-brand-slate">{{ $transaction->result_desc }}</p>
                            @endif
                            <p class="text-sm text-brand-slate">Please don't pay again until this is resolved — contact your landlord if you're not sure.</p>
                            <a href="{{ route('tenant.deposit') }}" wire:navigate class="inline-block text-sm text-teal hover:underline">Back to My Deposit</a>
                        </div>
                    @else
                        <div class="text-center space-y-3 py-4">
                            <div class="mx-auto h-10 w-10 rounded-full bg-red-50 text-danger flex items-center justify-center"><x-nav-icon name="x" class="h-5 w-5" /></div>
                            <p class="text-sm font-medium text-charcoal">
                                @if ($transaction->status === 'cancelled')
                                    Payment cancelled
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
