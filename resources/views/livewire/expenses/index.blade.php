    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Expenses</h2>
                <a href="{{ route('manage.expenses.create') }}" wire:navigate class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">+ Add Expense</a>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-50 border border-red-200 text-danger text-sm rounded-md px-4 py-2">{{ session('error') }}</div>
            @endif

            <div class="flex flex-wrap items-end gap-4">
                <div class="max-w-xs">
                    <x-stat-tile label="Total (filtered)" value="KSh {{ number_format($total, 2) }}" tone="critical" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Property</label>
                    <select wire:model.live="propertyId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Properties</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Category</label>
                    <select wire:model.live="categoryId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Month</label>
                    <input type="month" wire:model.live="month" class="rounded-md border-brand-border text-sm" />
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Date</th>
                            <th class="text-left px-5 py-2 font-medium">Property</th>
                            <th class="text-left px-5 py-2 font-medium">Category</th>
                            <th class="text-left px-5 py-2 font-medium">Vendor</th>
                            <th class="text-right px-5 py-2 font-medium">Amount</th>
                            <th class="text-left px-5 py-2 font-medium">Liability</th>
                            <th class="text-right px-5 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($expenses as $expense)
                            @php $decision = $decisionsByExpenseId[$expense->id] ?? null; @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-brand-slate">{{ $expense->expense_date->format('d M Y') }}</td>
                                <td class="px-5 py-3 text-charcoal">
                                    {{ $expense->property->name }}{{ $expense->house ? ', '.$expense->house->house_number : '' }}
                                    @if ($expense->deposit_deduction_id)
                                        <span class="block text-[11px] text-teal">Deducted from tenant deposit</span>
                                    @elseif ($expense->maintenanceTask?->complaint_id && ! $expense->maintenanceTask->complaint->tenant_confirmed_at)
                                        <span class="block text-[11px] text-warning">Awaiting tenant confirmation</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-brand-slate">{{ $expense->category?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $expense->vendor ?: '—' }}</td>
                                <td class="px-5 py-3 text-right font-medium text-danger">KSh {{ number_format($expense->amount, 2) }}</td>
                                <td class="px-5 py-3 text-brand-slate">
                                    @if (! $decision)
                                        <span class="text-xs text-gray-400">Undecided</span>
                                    @elseif ($decision->isLandlordResponsible())
                                        <span class="text-xs text-brand-slate">Landlord responsibility</span>
                                    @else
                                        <span class="text-xs text-teal">{{ $decision->tenancy?->tenant->name }} liable</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap space-x-3">
                                    @if (! $decision)
                                        <button wire:click="openLiabilityDecision({{ $expense->id }})" class="text-xs text-teal hover:underline">Decide Liability</button>
                                    @elseif ($this->canDeductFromDeposit($expense))
                                        <button wire:click="startDeduction({{ $expense->id }})" class="text-xs text-teal hover:underline">Deduct from Deposit</button>
                                    @endif
                                    <a href="{{ route('manage.expenses.edit', $expense) }}" wire:navigate class="text-xs text-brand-slate hover:text-charcoal">Edit</a>
                                    @if ($expense->deposit_deduction_id)
                                        <a href="{{ route('manage.tenancies.show', $expense->depositDeduction->tenancy_id) }}#deposit" wire:navigate class="text-xs text-brand-slate hover:text-charcoal">View/Void Deduction</a>
                                    @else
                                        <button wire:click="delete({{ $expense->id }})" wire:confirm="Delete this expense?" class="text-xs text-danger hover:underline">Delete</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-6 text-center text-gray-400">No expenses recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <x-per-page-select :paginator="$expenses" />

            @if ($deductingExpense && $deductingTenancy)
                <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="cancelDeduction">
                    <form wire:submit="confirmDeduction" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-sm space-y-3">
                        <h3 class="text-sm font-semibold text-charcoal">Deduct from {{ $deductingTenancy->tenant->name }}'s Deposit</h3>
                        <p class="text-xs text-brand-slate">
                            Currently held: KSh {{ number_format($deductingTenancy->depositHeldBalance(), 2) }}.
                            Only use this when the tenant is genuinely responsible, not normal wear and tear.
                        </p>
                        <div>
                            <x-input-label for="deduction_amount" value="Amount (KSh)" />
                            <x-text-input id="deduction_amount" type="number" step="0.01" wire:model="deduction_amount" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('deduction_amount')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deduction_date" value="Date" />
                            <x-text-input id="deduction_date" type="date" wire:model="deduction_date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('deduction_date')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deduction_reason" value="Reason" />
                            <textarea id="deduction_reason" wire:model="deduction_reason" rows="2" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                            <x-input-error :messages="$errors->get('deduction_reason')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deduction_evidence" value="Supporting Evidence (optional, PDF/JPG/PNG)" />
                            <input type="file" id="deduction_evidence" wire:model="deduction_evidence" class="mt-1 block w-full text-sm">
                            <x-input-error :messages="$errors->get('deduction_evidence')" class="mt-1" />
                        </div>
                        <div class="flex items-center gap-3">
                            <x-primary-button type="submit">Confirm Deduction</x-primary-button>
                            <button type="button" wire:click="cancelDeduction" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                        </div>
                    </form>
                </div>
            @endif

            @if ($decidingExpense)
                <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="cancelLiabilityDecision">
                    <div class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-md space-y-3">
                        <h3 class="text-sm font-semibold text-charcoal">Who's liable for this expense?</h3>
                        <p class="text-xs text-brand-slate">
                            {{ $decidingExpense->description ?: $decidingExpense->category?->name }} — KSh {{ number_format($decidingExpense->amount, 2) }} on {{ $decidingExpense->expense_date->format('d M Y') }}.
                        </p>

                        @if ($decidingCandidate && $decidingCandidate['kind'] === 'none')
                            <p class="text-xs text-warning">No tenancy could be matched to this expense's date automatically — select one below, or mark it a landlord responsibility.</p>
                        @elseif ($decidingCandidate && $decidingCandidate['kind'] === 'multiple')
                            <p class="text-xs text-warning">More than one tenancy record overlaps this expense's date — this needs a manual pick, not a guess.</p>
                        @elseif ($decidingCandidate && $decidingCandidate['kind'] === 'single')
                            <p class="text-xs text-teal">Occupying this unit on that date: {{ $decidingCandidate['tenancy']->tenant->name }} — pre-selected below, but this is a candidate signal only, not proof. Confirm or choose someone else.</p>
                        @endif

                        <form wire:submit="confirmTenantLiability" class="space-y-3">
                            <div>
                                <x-input-label for="liability_tenancy_id" value="Tenant liable" />
                                <select id="liability_tenancy_id" wire:model="liability_tenancy_id" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                    <option value="">Select a tenancy…</option>
                                    @foreach ($decidingTenancies as $t)
                                        <option value="{{ $t->id }}">{{ $t->tenant->name }} ({{ $t->move_in_date->format('M Y') }}{{ $t->move_out_date ? ' – '.$t->move_out_date->format('M Y') : ' – present' }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('liability_tenancy_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="liability_reason" value="Reason / evidence" />
                                <textarea id="liability_reason" wire:model="liability_reason" rows="2" class="mt-1 block w-full rounded-md border-brand-border" placeholder="e.g. tenant confirmed causing the damage"></textarea>
                                <x-input-error :messages="$errors->get('liability_reason')" class="mt-1" />
                            </div>
                            <div class="flex items-center gap-3">
                                <x-primary-button type="submit">Confirm Tenant Liability</x-primary-button>
                                <button type="button" wire:click="markLandlordResponsible" class="text-sm text-brand-slate hover:text-charcoal underline">Mark Landlord Responsibility Instead</button>
                            </div>
                        </form>
                        <button type="button" wire:click="cancelLiabilityDecision" class="text-xs text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </div>
            @endif
        </div>
    </div>
