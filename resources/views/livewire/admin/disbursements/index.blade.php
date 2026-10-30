    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Disbursements</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
            @endif

            <p class="text-sm text-brand-slate max-w-2xl">
                Rent tenants pay via M-Pesa lands in Kats Agencies' own paybill first. Record here whenever a landlord has
                actually been paid out — the transfer itself happens outside this system (M-Pesa or bank), this just keeps
                the ledger accurate. Recording a payout notifies the landlord and asks them to confirm receipt; it does not
                mark the money as received on its own. Scheduled release day: the 10th of each month,
                <span class="font-medium text-charcoal">{{ $daysUntilNextRelease === 0 ? 'today' : $daysUntilNextRelease.' day'.($daysUntilNextRelease === 1 ? '' : 's').' away' }}</span>.
            </p>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Landlord</th>
                            <th class="text-left px-5 py-2 font-medium">Collection Mode</th>
                            <th class="text-right px-5 py-2 font-medium">Collected (M-Pesa)</th>
                            <th class="text-right px-5 py-2 font-medium">Disbursed</th>
                            <th class="text-right px-5 py-2 font-medium">Owed</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-left px-5 py-2 font-medium">Last Disbursed</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($landlords as $row)
                            <tr>
                                <td class="px-5 py-2.5 text-charcoal font-medium">
                                    <a href="{{ route('admin.disbursements.show', $row->landlord->id) }}" wire:navigate class="hover:underline">{{ $row->landlord->name }}</a>
                                </td>
                                <td class="px-5 py-2.5">
                                    @if ($row->directCollection)
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-teal/10 text-teal">Direct, KSh {{ number_format($row->collectedDirectly, 2) }} all time</span>
                                    @elseif ($row->payoutConfigured)
                                        <x-status-badge status="active" />
                                    @else
                                        <span class="text-xs text-brand-slate">Payout not set up</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($row->collected, 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($row->disbursed, 2) }}</td>
                                <td class="px-5 py-2.5 text-right font-medium {{ $row->owed > 0 ? 'text-warning' : 'text-brand-slate' }}">KSh {{ number_format($row->owed, 2) }}</td>
                                <td class="px-5 py-2.5 space-x-1">
                                    @if ($row->awaitingConfirmationCount > 0)
                                        <x-status-badge status="awaiting_landlord_confirmation" />
                                    @endif
                                    @if ($row->disputedCount > 0)
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-danger/10 text-danger">{{ $row->disputedCount }} disputed</span>
                                    @endif
                                    @if ($row->awaitingConfirmationCount === 0 && $row->disputedCount === 0)
                                        <span class="text-xs text-brand-slate">&mdash;</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $row->lastDisbursement?->disbursed_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                    @if ($row->owed > 0)
                                        <button wire:click="startDisburse({{ $row->landlord->id }})" class="text-xs font-medium text-teal hover:underline">Disburse</button>
                                    @endif
                                    <a href="{{ route('admin.disbursements.show', $row->landlord->id) }}" wire:navigate class="text-xs font-medium text-brand-slate hover:text-charcoal ml-3">Statement</a>
                                </td>
                            </tr>
                            @if ($disbursingLandlordId === $row->landlord->id)
                                <tr>
                                    <td colspan="8" class="px-5 py-4 bg-offwhite">
                                        <form wire:submit="confirmDisburse" class="space-y-4">
                                            <div class="flex flex-wrap items-end gap-4">
                                                <div>
                                                    <x-input-label for="amount" value="Amount (KSh)" />
                                                    <x-text-input id="amount" type="number" step="0.01" wire:model="amount" class="mt-1 block w-36" />
                                                    <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="disbursedAt" value="Date" />
                                                    <x-text-input id="disbursedAt" type="date" wire:model="disbursedAt" class="mt-1 block" />
                                                    <x-input-error :messages="$errors->get('disbursedAt')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="destinationType" value="Payment Method" />
                                                    <select id="destinationType" wire:model="destinationType" class="mt-1 block w-36 rounded-md border-brand-border">
                                                        @foreach (\App\Livewire\Admin\Disbursements\Index::DESTINATION_TYPES as $value => $label)
                                                            <option value="{{ $value }}">{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$errors->get('destinationType')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="destinationShortcode" value="Shortcode / Account (optional)" />
                                                    <x-text-input id="destinationShortcode" type="text" wire:model="destinationShortcode" class="mt-1 block w-36" />
                                                </div>
                                                <div>
                                                    <x-input-label for="reference" value="Reference (optional)" />
                                                    <x-text-input id="reference" type="text" wire:model="reference" class="mt-1 block w-48" placeholder="M-Pesa/bank confirmation code" />
                                                    <x-input-error :messages="$errors->get('reference')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="payoutPeriodStart" value="Period from (optional)" />
                                                    <x-text-input id="payoutPeriodStart" type="date" wire:model="payoutPeriodStart" class="mt-1 block" />
                                                </div>
                                                <div>
                                                    <x-input-label for="payoutPeriodEnd" value="Period to (optional)" />
                                                    <x-text-input id="payoutPeriodEnd" type="date" wire:model="payoutPeriodEnd" class="mt-1 block" />
                                                    <x-input-error :messages="$errors->get('payoutPeriodEnd')" class="mt-1" />
                                                </div>
                                            </div>
                                            <div class="flex flex-wrap items-end gap-4">
                                                <div class="flex-1 min-w-[12rem]">
                                                    <x-input-label for="notes" value="Notes (optional)" />
                                                    <x-text-input id="notes" type="text" wire:model="notes" class="mt-1 block w-full" />
                                                </div>
                                                <div>
                                                    <x-input-label for="evidence" value="Receipt / evidence (optional, PDF/JPG/PNG)" />
                                                    <input id="evidence" type="file" wire:model="evidence" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block text-sm" />
                                                    <div wire:loading wire:target="evidence" class="text-xs text-brand-slate mt-1">Uploading…</div>
                                                    <x-input-error :messages="$errors->get('evidence')" class="mt-1" />
                                                </div>
                                                <x-primary-button type="submit">Record Disbursement</x-primary-button>
                                                <button type="button" wire:click="cancelDisburse" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>

            {{ $landlords->links() }}
        </div>
    </div>
