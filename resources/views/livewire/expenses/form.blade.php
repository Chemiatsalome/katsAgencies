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
                                <select id="house_id" wire:model="house_id" class="mt-1 block w-full rounded-md border-brand-border">
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

                <div class="flex items-center gap-3">
                    <x-primary-button type="submit">{{ $expense ? 'Update Expense' : 'Save Expense' }}</x-primary-button>
                    <a href="{{ route('manage.expenses.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                </div>
            </form>
        </div>
    </div>
