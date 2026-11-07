    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Incoming Payments</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <p class="text-sm text-brand-slate max-w-2xl">
                Every M-Pesa payment attempt across the platform — both Kats' own paybill and landlords' direct
                collection — whether or not it ever became a confirmed payment. Use this to spot stuck, failed or
                unmatched transactions. For payout-level tracking, see <a href="{{ route('admin.disbursements.index') }}" wire:navigate class="text-teal hover:underline">Disbursements</a> instead.
            </p>

            <div class="flex flex-wrap items-center gap-2">
                @foreach (['all' => 'All', 'success' => 'Success', 'pending' => 'Pending', 'failed' => 'Failed', 'cancelled' => 'Cancelled', 'timeout' => 'Timed Out'] as $value => $label)
                    <button
                        wire:click="$set('status', '{{ $value }}')"
                        class="px-3 py-1.5 rounded-full text-xs font-medium border {{ $status === $value ? 'bg-navy text-white border-navy' : 'bg-white text-brand-slate border-brand-border hover:border-navy' }}"
                    >
                        {{ $label }}
                        @if ($value !== 'all' && ($statusCounts[$value] ?? 0) > 0)
                            <span class="ms-1 opacity-75">{{ $statusCounts[$value] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search receipt, checkout ID or phone…"
                    class="rounded-md border-brand-border text-sm w-64"
                >
                <select wire:model.live="landlordId" class="rounded-md border-brand-border text-sm">
                    <option value="all">All Landlords</option>
                    @foreach ($landlords as $landlord)
                        <option value="{{ $landlord->id }}">{{ $landlord->name }}</option>
                    @endforeach
                </select>
            </div>

            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <table class="min-w-full divide-y divide-brand-border text-sm">
                    <thead class="bg-offwhite">
                        <tr class="text-left text-xs font-medium text-brand-slate uppercase tracking-wide">
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Tenant / Property</th>
                            <th class="px-4 py-3">Landlord</th>
                            <th class="px-4 py-3">Mode</th>
                            <th class="px-4 py-3">Amount</th>
                            <th class="px-4 py-3">Reference</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Investigation</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-border">
                        @forelse ($transactions as $t)
                            <tr class="hover:bg-offwhite/60">
                                <td class="px-4 py-3 text-brand-slate whitespace-nowrap">{{ $t->created_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-3">
                                    @if ($t->purpose === 'sms_topup')
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-navy-light text-navy">SMS Wallet Top-up</span>
                                    @elseif ($t->tenancy)
                                        <div class="font-medium text-charcoal">{{ $t->tenancy->tenant?->name ?? '—' }}</div>
                                        <div class="text-xs text-brand-slate">{{ $t->tenancy->house?->house_number }} · {{ $t->tenancy->house?->block?->property?->name }}</div>
                                    @else
                                        <span class="text-brand-slate">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-brand-slate">{{ ($t->purpose === 'sms_topup' ? $t->landlord?->name : $t->tenancy?->house?->block?->property?->landlord?->name) ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($t->collection_mode)
                                        <x-status-badge :status="$t->collection_mode" />
                                    @else
                                        <span class="text-xs text-brand-slate">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-medium text-charcoal">KSh {{ number_format($t->amount, 2) }}</td>
                                <td class="px-4 py-3 text-xs text-brand-slate">
                                    {{ $t->mpesa_receipt_number ?? $t->checkout_request_id ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-badge :status="$t->status" />
                                    @if ($t->status !== 'success' && $t->result_desc)
                                        <p class="text-xs text-brand-slate mt-1 max-w-xs">{{ $t->result_desc }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs font-medium text-charcoal">{{ $t->investigationState() }}</div>
                                    @if ($t->statusQueries->isNotEmpty())
                                        <div class="text-xs text-brand-slate mt-0.5">{{ $t->statusQueries->count() }} {{ Str::plural('query', $t->statusQueries->count()) }} · last {{ $t->last_queried_at?->diffForHumans() }}</div>
                                    @endif
                                    @if ($t->investigation_notes)
                                        <p class="text-xs text-brand-slate mt-1 max-w-xs">{{ $t->investigation_notes }}</p>
                                    @endif

                                    @if ($t->purpose !== 'sms_topup' && ! $t->isFinished())
                                        <div class="flex flex-wrap items-center gap-2 mt-2">
                                            <button wire:click="requery({{ $t->id }})" class="text-xs text-teal hover:underline">Re-query</button>
                                            <button wire:click="startResolve({{ $t->id }}, 'successful')" class="text-xs text-teal hover:underline">Mark successful</button>
                                            <button wire:click="startResolve({{ $t->id }}, 'failed')" class="text-xs text-danger hover:underline">Mark failed</button>
                                            <button wire:click="startResolve({{ $t->id }}, 'cancelled')" class="text-xs text-danger hover:underline">Mark cancelled</button>
                                            @unless ($t->needsManualVerification())
                                                <button wire:click="startResolve({{ $t->id }}, 'escalate')" class="text-xs text-warning hover:underline">Escalate</button>
                                            @endunless
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-brand-slate">No transactions match these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $transactions->links() }}

            @if ($resolvingTransactionId)
                <div class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50" wire:click.self="cancelResolve">
                    <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-6 space-y-4">
                        <h3 class="font-semibold text-charcoal">
                            @switch($resolveAction)
                                @case('successful') Mark as successfully resolved @break
                                @case('failed') Mark as failed after verification @break
                                @case('cancelled') Mark as cancelled after verification @break
                                @case('escalate') Escalate for manual verification @break
                            @endswitch
                        </h3>

                        @if ($resolveAction === 'successful')
                            <div>
                                <x-input-label for="resolveReference" value="Reference of the already-recorded, confirmed payment" />
                                <x-text-input id="resolveReference" type="text" wire:model="resolveReference" class="mt-1 block w-full" />
                                <p class="text-xs text-brand-slate mt-1">Must match a confirmed Payment/Deposit Payment already recorded for this tenancy (e.g. via Record Payment) — this action links to it, it never credits on its own.</p>
                                <x-input-error :messages="$errors->get('resolveReference')" class="mt-1" />
                            </div>
                        @endif

                        <div>
                            <x-input-label for="resolveReason" value="Reason / evidence" />
                            <textarea id="resolveReason" wire:model="resolveReason" rows="3" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                            <x-input-error :messages="$errors->get('resolveReason')" class="mt-1" />
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
