    <div>
        <x-slot name="header">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-semibold text-lg sm:text-xl text-charcoal leading-tight">Payment Investigation</h2>
                    <x-status-badge :status="$transaction->status" />
                </div>
                <p class="mt-1.5 text-xs sm:text-sm text-brand-slate">
                    {{ $transaction->tenancy->tenant?->name }} &middot; {{ $transaction->tenancy->house?->block?->property?->name }} &middot; Unit {{ $transaction->tenancy->house?->house_number }}
                </p>
            </div>
        </x-slot>

        <div class="p-6 space-y-6 max-w-3xl">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-danger/10 border border-danger/30 text-danger px-4 py-2 text-sm">{{ session('error') }}</div>
            @endif
            @error('general')
                <div class="rounded-md bg-danger/10 border border-danger/30 text-danger px-4 py-2 text-sm">{{ $message }}</div>
            @enderror

            {{-- Why this landlord is being asked to look at this at all — a
                 tenant's own cancellation request and an automated no-answer
                 escalation are different events, never presented identically. --}}
            <div class="rounded-md bg-navy-light border border-navy/20 text-charcoal text-sm px-4 py-3">
                {{ $transaction->investigationReason() }}
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-brand-slate">Transaction Details</h3>
                </div>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <tr>
                            <td class="px-5 py-2 text-brand-slate w-1/3">Payment purpose</td>
                            <td class="px-5 py-2 text-charcoal font-medium">{{ $transaction->purpose === 'deposit' ? 'Deposit' : 'Rent' }}</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2 text-brand-slate">Amount</td>
                            <td class="px-5 py-2 text-charcoal font-medium">KSh {{ number_format($transaction->amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2 text-brand-slate">Attempted on</td>
                            <td class="px-5 py-2 text-charcoal">{{ $transaction->created_at->format('d M Y, H:i') }}</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2 text-brand-slate">M-Pesa phone number</td>
                            <td class="px-5 py-2 text-charcoal">{{ $transaction->phone ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2 text-brand-slate">M-Pesa receipt / reference</td>
                            <td class="px-5 py-2 text-charcoal">{{ $transaction->mpesa_receipt_number ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2 text-brand-slate">Current state</td>
                            <td class="px-5 py-2 text-charcoal">{{ $transaction->investigationState() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if ($transaction->landlordResponded())
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5 space-y-2">
                    <h3 class="text-sm font-semibold text-brand-slate">Your Response</h3>
                    <p class="text-sm text-charcoal">
                        You said this payment was
                        <span class="font-medium {{ $transaction->landlordConfirmedReceived() ? 'text-success' : 'text-danger' }}">
                            {{ $transaction->landlordConfirmedReceived() ? 'received' : 'not received' }}
                        </span>
                        on {{ $transaction->landlord_response_at->format('d M Y, H:i') }}.
                    </p>
                    @if ($transaction->landlordConfirmedReceived())
                        <p class="text-sm text-brand-slate">
                            This has been passed to the admin team. It will not reflect in the tenant's balance until the actual
                            payment is recorded through <a href="{{ route('manage.tenancies.show', $transaction->tenancy_id) }}#{{ $transaction->purpose === 'deposit' ? 'deposit' : 'payments' }}" wire:navigate class="text-teal hover:underline font-medium">{{ $transaction->tenancy->house?->house_number }}'s tenancy page</a>
                            and the original transaction is formally resolved.
                        </p>
                    @else
                        <p class="text-sm text-brand-slate">This has been passed to the admin team for investigation. The tenant cannot retry until it's resolved.</p>
                    @endif
                </div>
            @elseif (! $transaction->isFinished())
                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5 space-y-3">
                        <h3 class="text-sm font-semibold text-charcoal">I received this payment</h3>
                        <p class="text-xs text-brand-slate">
                            This only tells us you've checked — it does not credit the tenant's balance on its own. You'll still need to
                            record the actual payment through the existing Record Payment workflow, and the admin team will verify it
                            before the original M-Pesa transaction is closed out.
                        </p>
                        <button wire:click="confirmReceived" wire:confirm="Confirm you received this KSh {{ number_format($transaction->amount, 2) }} payment?" class="w-full text-sm font-medium text-white bg-navy rounded-md px-3 py-2 hover:bg-navy-dark">
                            I received this payment
                        </button>
                    </div>
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5 space-y-3">
                        <h3 class="text-sm font-semibold text-charcoal">I did not receive this payment</h3>
                        <p class="text-xs text-brand-slate">
                            This flags the original transaction for admin investigation. It does not cancel or fail it automatically,
                            and the tenant will stay unable to retry until the admin safely resolves it based on sufficient evidence.
                        </p>
                        <button wire:click="confirmNotReceived" wire:confirm="Confirm you did not receive this KSh {{ number_format($transaction->amount, 2) }} payment?" class="w-full text-sm font-medium text-charcoal border border-brand-border rounded-md px-3 py-2 hover:bg-offwhite">
                            I did not receive this payment
                        </button>
                    </div>
                </div>
            @else
                <div class="rounded-md bg-gray-100 text-brand-slate text-sm px-4 py-3">
                    This transaction has already been resolved — no further response is needed.
                </div>
            @endif

            @if ($transaction->statusQueries->isNotEmpty())
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="px-5 py-4 border-b border-brand-border">
                        <h3 class="text-sm font-semibold text-brand-slate">Investigation History</h3>
                    </div>
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($transaction->statusQueries as $query)
                            <li class="px-5 py-2 text-brand-slate">
                                {{ $query->created_at->format('d M Y, H:i') }} &middot; {{ ucfirst(str_replace('_', ' ', $query->source)) }}
                                &middot; {{ ucfirst(str_replace('_', ' ', $query->interpreted_as ?? 'inconclusive')) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
