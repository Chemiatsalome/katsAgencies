    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $expense ? 'Edit Expense' : 'Add Expense' }}</h2>
        </x-slot>

        <div class="p-6">
            <form wire:submit="save" class="max-w-xl bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                <div>
                    <x-input-label for="property_id" value="Property" />
                    <select id="property_id" wire:model.live="property_id" class="mt-1 block w-full rounded-md border-brand-border">
                        <option value="">Select property…</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('property_id')" class="mt-1" />
                </div>

                @if ($property_id)
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="block_id" value="Block (optional)" />
                            <select id="block_id" wire:model.live="block_id" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="">Whole property</option>
                                @foreach ($blocks as $block)
                                    <option value="{{ $block->id }}">{{ $block->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($block_id)
                            <div>
                                <x-input-label for="house_id" value="Unit (optional)" />
                                <select id="house_id" wire:model.live="house_id" class="mt-1 block w-full rounded-md border-brand-border">
                                    <option value="">Whole block</option>
                                    @foreach ($houses as $house)
                                        <option value="{{ $house->id }}">{{ $house->house_number }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="expense_category_id" value="Category" />
                        <select id="expense_category_id" wire:model="expense_category_id" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="">Select category…</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('expense_category_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="expense_date" value="Date" />
                        <x-text-input id="expense_date" type="date" wire:model="expense_date" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('expense_date')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="vendor" value="Vendor / Paid To (optional)" />
                        <x-text-input id="vendor" type="text" wire:model="vendor" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="amount" value="Amount (KSh)" />
                        <x-text-input id="amount" type="number" step="0.01" wire:model="amount" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="description" value="Description (optional)" />
                    <textarea id="description" wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                </div>

                @if (! $expense)
                    <div class="border-t border-brand-border pt-4">
                        <x-input-label value="Who should bear this cost?" />
                        <div class="mt-1 flex gap-4 text-sm">
                            <label class="flex items-center gap-1.5">
                                <input type="radio" wire:model.live="cost_responsibility" value="landlord" class="border-brand-border">
                                Landlord
                            </label>
                            <label class="flex items-center gap-1.5 {{ ! $house_id ? 'opacity-50' : '' }}">
                                <input type="radio" wire:model.live="cost_responsibility" value="tenant" class="border-brand-border" {{ ! $house_id ? 'disabled' : '' }}>
                                Tenant — deduct from security deposit
                            </label>
                        </div>
                        @if (! $house_id)
                            <p class="text-xs text-brand-slate mt-1">Select a unit above to charge this to a tenant's deposit.</p>
                        @endif

                        @if ($cost_responsibility === 'tenant' && $house_id)
                            <div class="mt-3 space-y-3">
                                <div>
                                    <x-input-label for="liability_tenancy_id" value="Tenant liable" />
                                    <select id="liability_tenancy_id" wire:model.live="liability_tenancy_id" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                        <option value="">Select a tenancy…</option>
                                        @foreach ($candidateTenancies as $t)
                                            <option value="{{ $t->id }}">{{ $t->tenant->name }} ({{ $t->move_in_date->format('M Y') }}{{ $t->move_out_date ? ' – '.$t->move_out_date->format('M Y') : ' – present' }})</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('liability_tenancy_id')" class="mt-1" />
                                </div>

                                @if ($selectedTenancy)
                                    <p class="text-xs text-brand-slate">
                                        Currently held: KSh {{ number_format($selectedTenancy->depositHeldBalance(), 2) }}.
                                        Only use this when the cost is genuinely the tenant's responsibility, not normal wear and tear.
                                    </p>
                                @endif

                                <div>
                                    <x-input-label for="deduction_amount" value="Amount to Deduct (KSh)" />
                                    <x-text-input id="deduction_amount" type="number" step="0.01" wire:model="deduction_amount" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('deduction_amount')" class="mt-1" />
                                    @if ($amount && (float) $deduction_amount > 0 && (float) $deduction_amount < (float) $amount)
                                        <p class="text-xs text-warning mt-1">
                                            KSh {{ number_format((float) $amount - (float) $deduction_amount, 2) }} of this expense will remain unrecovered — bill the tenant directly, or decide once more is held.
                                        </p>
                                    @endif
                                </div>

                                <div>
                                    <x-input-label for="deduction_reason" value="Reason" />
                                    <textarea id="deduction_reason" wire:model="deduction_reason" rows="2" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                                    <x-input-error :messages="$errors->get('deduction_reason')" class="mt-1" />
                                </div>

                                <div>
                                    <x-input-label for="deduction_evidence" value="Supporting Evidence (optional, PDF/JPG/PNG)" />
                                    <input type="file" id="deduction_evidence" wire:model="deduction_evidence" class="mt-1 block w-full text-sm">
                                    <x-input-error :messages="$errors->get('deduction_evidence')" class="mt-1" />
                                </div>

                                <p class="text-xs text-brand-slate">
                                    This proposes the deduction — it won't count against the tenant's held deposit until they acknowledge it, or you resolve a dispute in your favour.
                                </p>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="flex items-center gap-3">
                    <x-primary-button type="submit">{{ $expense ? 'Update Expense' : 'Save Expense' }}</x-primary-button>
                    <a href="{{ route('manage.expenses.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                </div>
            </form>
        </div>
    </div>
