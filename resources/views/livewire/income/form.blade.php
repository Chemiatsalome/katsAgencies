    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $income ? 'Edit Income' : 'Add Income' }}</h2>
        </x-slot>

        <div class="p-6">
            <form wire:submit="save" class="max-w-xl bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                <div>
                    <x-input-label for="property_id" value="Property" />
                    <select id="property_id" wire:model="property_id" class="mt-1 block w-full rounded-md border-brand-border">
                        <option value="">Select property…</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('property_id')" class="mt-1" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="income_category_id" value="Category" />
                        <select id="income_category_id" wire:model="income_category_id" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="">Select category…</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('income_category_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="income_date" value="Date" />
                        <x-text-input id="income_date" type="date" wire:model="income_date" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('income_date')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="amount" value="Amount (KSh)" />
                    <x-text-input id="amount" type="number" step="0.01" wire:model="amount" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="description" value="Description (optional)" />
                    <textarea id="description" wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button type="submit">{{ $income ? 'Update Income' : 'Save Income' }}</x-primary-button>
                    <a href="{{ route('manage.income.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                </div>
            </form>
        </div>
    </div>
