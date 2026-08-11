    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-brand-slate">
                        <a href="{{ route('manage.tenancies.index') }}" wire:navigate class="hover:underline">Tenancies</a>
                    </p>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $tenancy->tenant->name }}</h2>
                </div>
                @can('update', $tenancy)
                    <div class="flex items-center gap-2">
                        @if (in_array($tenancy->status, \App\Models\Tenancy::OCCUPYING_STATUSES))
                            @if ($tenancy->status !== 'notice_given')
                                <button wire:click="giveNotice" class="text-sm text-charcoal border border-brand-border rounded-md px-3 py-1.5 hover:bg-offwhite">
                                    Notice Given
                                </button>
                            @endif
                            <button wire:click="$toggle('showMoveOutForm')" class="text-sm text-danger border border-red-200 rounded-md px-3 py-1.5">
                                End Tenancy
                            </button>
                        @endif
                    </div>
                @endcan
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            @if ($showMoveOutForm)
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">End Tenancy</h3>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" wire:model="moveOutStatus" value="completed" /> Completed (lease ran its course)
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" wire:model="moveOutStatus" value="terminated" /> Terminated (ended early)
                        </label>
                    </div>
                    @if ($moveOutStatus === 'terminated')
                        <div>
                            <x-input-label for="terminationReason" value="Reason for termination" />
                            <x-text-input id="terminationReason" type="text" wire:model="terminationReason" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('terminationReason')" class="mt-1" />
                        </div>
                    @endif
                    <div class="flex gap-3">
                        <x-primary-button wire:click="moveOut" wire:confirm="Confirm ending this tenancy and vacating the unit?">Confirm</x-primary-button>
                        <x-secondary-button wire:click="$set('showMoveOutForm', false)">Cancel</x-secondary-button>
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="House" value="{{ $tenancy->house->house_number }}">{{ $tenancy->house->block->property->name }} / {{ $tenancy->house->block->name }}</x-stat-tile>
                <x-stat-tile label="Monthly Rent" value="KSh {{ number_format($tenancy->monthly_rent) }}" />
                <x-stat-tile label="Balance" value="KSh {{ number_format($tenancy->balance()) }}" tone="{{ $tenancy->balance() > 0 ? 'critical' : 'good' }}" />
                <x-stat-tile label="Deposit Balance" value="KSh {{ number_format($tenancy->depositBalance()) }}" tone="{{ $tenancy->depositBalance() > 0 ? 'warning' : 'good' }}" />
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <p class="text-brand-slate">Phone</p>
                    <p class="text-charcoal">{{ $tenancy->tenant->phone }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Move-in Date</p>
                    <p class="text-charcoal">{{ $tenancy->move_in_date->format('d M Y') }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Lease Expiry</p>
                    <p class="text-charcoal">{{ $tenancy->lease_end_date?->format('d M Y') ?? 'Open-ended' }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Status</p>
                    <x-status-badge :status="$tenancy->status" />
                </div>
                @if ($tenancy->notice_date)
                    <div>
                        <p class="text-brand-slate">Notice Given</p>
                        <p class="text-charcoal">{{ $tenancy->notice_date->format('d M Y') }}</p>
                    </div>
                @endif
                @if ($tenancy->move_out_date)
                    <div>
                        <p class="text-brand-slate">Move-out Date</p>
                        <p class="text-charcoal">{{ $tenancy->move_out_date->format('d M Y') }}</p>
                    </div>
                @endif
                @if ($tenancy->termination_reason)
                    <div class="col-span-2">
                        <p class="text-brand-slate">Termination Reason</p>
                        <p class="text-charcoal">{{ $tenancy->termination_reason }}</p>
                    </div>
                @endif
            </div>

            @can('update', $tenancy)
                @if (in_array($tenancy->status, \App\Models\Tenancy::OCCUPYING_STATUSES))
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <livewire:payments.record-payment :tenancy="$tenancy" :key="'record-payment-'.$tenancy->id" />
                        <livewire:deposits.record-deposit :tenancy="$tenancy" :key="'record-deposit-'.$tenancy->id" />
                    </div>
                @endif
            @endcan

            <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-brand-slate">Rent Charges</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Period</th>
                                <th class="text-left px-5 py-2 font-medium">Due Date</th>
                                <th class="text-right px-5 py-2 font-medium">Expected</th>
                                <th class="text-right px-5 py-2 font-medium">Paid</th>
                                <th class="text-right px-5 py-2 font-medium">Balance</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="text-left px-5 py-2 font-medium">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($rentCharges as $charge)
                                <tr>
                                    <td class="px-5 py-2 text-charcoal">{{ \Carbon\Carbon::create()->month($charge->period_month)->format('F') }} {{ $charge->period_year }}</td>
                                    <td class="px-5 py-2 text-brand-slate">{{ $charge->due_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2 text-right text-brand-slate">KSh {{ number_format($charge->amount_expected, 2) }}</td>
                                    <td class="px-5 py-2 text-right text-brand-slate">KSh {{ number_format($charge->amount_paid, 2) }}</td>
                                    <td class="px-5 py-2 text-right {{ $charge->amount_expected > $charge->amount_paid ? 'text-danger' : 'text-brand-slate' }}">
                                        KSh {{ number_format($charge->amount_expected - $charge->amount_paid, 2) }}
                                    </td>
                                    <td class="px-5 py-2"><x-status-badge :status="$charge->status" /></td>
                                    <td class="px-5 py-2 text-brand-slate max-w-xs truncate" title="{{ $charge->notes }}">{{ $charge->notes }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-6 text-center text-gray-400">No rent charges generated yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-brand-slate">Payment History</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Date</th>
                                <th class="text-left px-5 py-2 font-medium">Method</th>
                                <th class="text-left px-5 py-2 font-medium">Reference</th>
                                <th class="text-right px-5 py-2 font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($payments as $payment)
                                <tr>
                                    <td class="px-5 py-2 text-brand-slate">{{ $payment->payment_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2 text-brand-slate">{{ ucfirst($payment->method) }}</td>
                                    <td class="px-5 py-2 text-brand-slate">{{ $payment->reference_number ?? '—' }}</td>
                                    <td class="px-5 py-2 text-right text-charcoal">KSh {{ number_format($payment->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-6 text-center text-gray-400">No payments recorded.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                <div class="px-5 py-4 border-b border-brand-border flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-brand-slate">Deposit</h3>
                    @can('update', $tenancy)
                        @if ($tenancy->status === 'completed' || $tenancy->status === 'terminated')
                            <button wire:click="$toggle('showRefundForm')" class="text-xs text-teal hover:underline">Record Refund</button>
                        @endif
                    @endcan
                </div>

                @if ($showRefundForm)
                    <div class="p-5 border-b border-brand-border space-y-3">
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <x-input-label for="refund_amount" value="Refund Amount (KSh)" />
                                <x-text-input id="refund_amount" type="number" step="0.01" wire:model="refund_amount" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('refund_amount')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="refund_deductions" value="Deductions (KSh)" />
                                <x-text-input id="refund_deductions" type="number" step="0.01" wire:model="refund_deductions" class="mt-1 block w-full" />
                            </div>
                            <div>
                                <x-input-label for="refund_reason" value="Deduction Reason" />
                                <x-text-input id="refund_reason" type="text" wire:model="refund_reason" class="mt-1 block w-full" />
                            </div>
                        </div>
                        <x-primary-button wire:click="recordRefund">Save Refund</x-primary-button>
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Date</th>
                                <th class="text-left px-5 py-2 font-medium">Method</th>
                                <th class="text-right px-5 py-2 font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($depositPayments as $deposit)
                                <tr>
                                    <td class="px-5 py-2 text-brand-slate">{{ $deposit->payment_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2 text-brand-slate">{{ ucfirst($deposit->method) }}</td>
                                    <td class="px-5 py-2 text-right text-charcoal">KSh {{ number_format($deposit->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-6 text-center text-gray-400">No deposit payments recorded.</td>
                                </tr>
                            @endforelse
                            @foreach ($depositRefunds as $refund)
                                <tr>
                                    <td class="px-5 py-2 text-brand-slate">{{ $refund->refund_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2 text-danger">Refunded {{ $refund->deduction_reason ? '('.$refund->deduction_reason.')' : '' }}</td>
                                    <td class="px-5 py-2 text-right text-danger">
                                        -KSh {{ number_format($refund->amount, 2) }}
                                        @if ($refund->deductions > 0)
                                            <span class="text-xs text-brand-slate block">Deducted KSh {{ number_format($refund->deductions, 2) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5">
                <h3 class="text-sm font-semibold text-brand-slate mb-3">Lease Document</h3>
                <livewire:documents.manager :model="$tenancy" :key="'docs-tenancy-'.$tenancy->id" />
            </div>
        </div>
    </div>
