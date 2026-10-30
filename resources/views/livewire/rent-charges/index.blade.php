    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Rent &amp; Payments</h2>
                <a href="{{ route('manage.rent-charges.historical-review') }}" wire:navigate class="text-sm text-teal hover:underline">
                    Historical Rent Review
                </a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-brand-slate">Property</label>
                        <select wire:model.live="propertyId" class="rounded-md border-brand-border text-sm">
                            <option value="all">All Properties</option>
                            @foreach ($properties as $property)
                                <option value="{{ $property->id }}">{{ $property->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="text-sm text-brand-slate">Year</label>
                        <select wire:model.live="year" class="rounded-md border-brand-border text-sm">
                            @foreach ($availableYears as $y)
                                <option value="{{ $y }}">{{ $y }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="text-sm text-brand-slate">Month</label>
                        <select wire:model.live="month" class="rounded-md border-brand-border text-sm">
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}">{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                            @endforeach
                        </select>
                    </div>

                    <select wire:model.live="status" class="rounded-md border-brand-border text-sm">
                        <option value="all">All statuses</option>
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="overdue">Overdue</option>
                        <option value="advance">Advance</option>
                    </select>
                </div>

                <h3 class="text-sm font-semibold text-charcoal">
                    {{ $propertyId !== 'all' ? $properties->firstWhere('id', (int) $propertyId)?->name : 'All Properties' }}
                    — {{ \Carbon\Carbon::create()->month($month)->format('F') }} {{ $year }}
                </h3>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                    @if ($propertyId === 'all')
                                        <th class="text-left px-5 py-2 font-medium">Property</th>
                                    @endif
                                    <th class="text-left px-5 py-2 font-medium">Block</th>
                                    <th class="text-left px-5 py-2 font-medium">Unit</th>
                                    <th class="text-left px-5 py-2 font-medium">Due Date</th>
                                    <th class="text-right px-5 py-2 font-medium">Expected</th>
                                    <th class="text-right px-5 py-2 font-medium">Paid</th>
                                    <th class="text-right px-5 py-2 font-medium">Balance</th>
                                    <th class="text-right px-5 py-2 font-medium">Credit Applied</th>
                                    <th class="text-right px-5 py-2 font-medium">Net Due</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                    <th class="px-5 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($charges as $charge)
                                    @php
                                        $creditApplied = (float) ($creditByChargeId[$charge->id] ?? 0);
                                        $grossBalance = max((float) $charge->amount_expected - (float) $charge->amount_paid, 0);
                                        $netDue = max(0, $grossBalance - $creditApplied);
                                    @endphp
                                    <tr wire:key="charge-{{ $charge->id }}" class="hover:bg-gray-50">
                                        <td class="px-5 py-3 font-medium text-charcoal">
                                            <a href="{{ route('manage.tenancies.show', $charge->tenancy) }}" wire:navigate class="hover:underline">{{ $charge->tenancy->tenant->name }}</a>
                                        </td>
                                        @if ($propertyId === 'all')
                                            <td class="px-5 py-3 text-brand-slate">{{ $charge->tenancy->house->block->property->name }}</td>
                                        @endif
                                        <td class="px-5 py-3 text-brand-slate">{{ $charge->tenancy->house->block->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $charge->tenancy->house->house_number }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $charge->due_date->format('d M Y') }}</td>
                                        <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($charge->amount_expected, 2) }}</td>
                                        <td class="px-5 py-3 text-right text-brand-slate">KSh {{ number_format($charge->amount_paid, 2) }}</td>
                                        <td class="px-5 py-3 text-right {{ $grossBalance > 0 ? 'text-danger' : 'text-brand-slate' }}">
                                            KSh {{ number_format($grossBalance, 2) }}
                                        </td>
                                        <td class="px-5 py-3 text-right {{ $creditApplied > 0 ? 'text-teal' : 'text-gray-400' }}">
                                            {{ $creditApplied > 0 ? 'KSh '.number_format($creditApplied, 2) : '—' }}
                                        </td>
                                        <td class="px-5 py-3 text-right {{ $netDue > 0 ? 'text-danger' : 'text-brand-slate' }} font-medium">
                                            KSh {{ number_format($netDue, 2) }}
                                            @if ($grossBalance > 0 && $netDue <= 0)
                                                <span class="block text-[11px] text-teal font-normal">Covered by advance credit</span>
                                            @endif
                                        </td>
                                        @php
                                            $classification = $charge->historicalClassification($this->financialStartDateFor($charge));
                                            $historicalLabel = match ($classification) {
                                                'historical_settled' => match ($charge->payments->first(fn ($p) => $p->isRevertibleSource())?->source) {
                                                    'seeded_data', 'historical_import' => 'Historical, Imported as Paid',
                                                    default => 'Historical, Assumed Paid',
                                                },
                                                'historical_unpaid' => 'Historical, Unpaid',
                                                'system_payment' => 'Paid through System',
                                                default => null,
                                            };
                                            $historicalDotClass = match ($classification) {
                                                'historical_unpaid' => 'bg-danger',
                                                'system_payment' => 'bg-success',
                                                default => 'bg-warning',
                                            };
                                        @endphp
                                        <td class="px-5 py-3">
                                            <x-status-badge :status="$charge->status" />
                                            @if ($historicalLabel)
                                                <span class="flex items-center gap-1.5 text-[11px] text-brand-slate mt-0.5">
                                                    <span class="h-1.5 w-1.5 rounded-full {{ $historicalDotClass }} shrink-0"></span>
                                                    {{ $historicalLabel }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right whitespace-nowrap">
                                            @can('update', $charge->tenancy)
                                                @if ($charge->isRevertible())
                                                    <button
                                                        wire:click="revertHistoricalPayment({{ $charge->id }})"
                                                        wire:confirm="This is a historical/imported payment record. Reverting it will remove the paid status and add the applicable rent to the tenant's outstanding balance.&#10;&#10;This action does not affect payments recorded through the platform."
                                                        class="text-danger hover:underline text-xs font-medium"
                                                    >Revert to Unpaid</button>
                                                @else
                                                    @if ($charge->status !== 'paid' && $charge->status !== 'advance')
                                                        <button
                                                            wire:click="markPaid({{ $charge->id }})"
                                                            wire:confirm="Mark this rent charge as paid in full?"
                                                            class="text-success hover:underline text-xs font-medium"
                                                        >Mark as Paid</button>
                                                    @endif
                                                    <button wire:click="openPaymentModal({{ $charge->id }})" class="text-teal hover:underline text-xs font-medium ml-2">
                                                        Record Payment
                                                    </button>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="px-5 py-6 text-center text-gray-400">No tenants had rent due for this property in this period.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <x-per-page-select :paginator="$charges" />
            </div>
        </div>

        @if ($payingCharge)
            <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closePaymentModal">
                <div class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-md space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-charcoal">
                            Record Payment — {{ $payingCharge->tenancy->tenant->name }}
                        </h3>
                        <button wire:click="closePaymentModal" class="text-brand-slate hover:text-charcoal">&times;</button>
                    </div>

                    @php $modalCreditApplied = (float) ($creditByChargeId[$payingCharge->id] ?? 0); @endphp
                    <p class="text-xs text-brand-slate">
                        Expected KSh {{ number_format($payingCharge->amount_expected, 2) }} ·
                        Already paid KSh {{ number_format($payingCharge->amount_paid, 2) }} ·
                        Balance KSh {{ number_format(max($payingCharge->amount_expected - $payingCharge->amount_paid, 0), 2) }}
                        @if ($modalCreditApplied > 0)
                            <span class="block text-teal mt-0.5">KSh {{ number_format($modalCreditApplied, 2) }} of this is already covered by advance credit from a prior overpayment.</span>
                        @endif
                    </p>

                    <form wire:submit="recordPayment" class="space-y-4">
                        <div>
                            <x-input-label for="payment_amount" value="Amount (KSh)" />
                            <x-text-input id="payment_amount" type="number" step="0.01" wire:model="payment_amount" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('payment_amount')" class="mt-1" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <x-input-label for="payment_date" value="Payment Date" />
                                <x-text-input id="payment_date" type="date" wire:model="payment_date" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('payment_date')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="payment_method" value="Method" />
                                <select id="payment_method" wire:model="payment_method" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                    <option value="mpesa">M-Pesa</option>
                                    <option value="bank">Bank</option>
                                    <option value="cash">Cash</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <x-input-label for="payment_reference" value="Transaction / Reference No. (optional)" />
                            <x-text-input id="payment_reference" type="text" wire:model="payment_reference" class="mt-1 block w-full" />
                        </div>

                        <div>
                            <x-input-label for="payment_notes" value="Notes (optional)" />
                            <x-text-input id="payment_notes" type="text" wire:model="payment_notes" class="mt-1 block w-full" />
                        </div>

                        <div class="flex items-center gap-3">
                            <x-primary-button type="submit">Save Payment</x-primary-button>
                            <button type="button" wire:click="closePaymentModal" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
