    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $house ? 'Edit House' : 'Add House' }} — {{ $block->property->name }} / {{ $block->name }}</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <form wire:submit="save" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="house_number" value="House Number" />
                            <x-text-input id="house_number" type="text" wire:model="house_number" class="mt-1 block w-full" placeholder="e.g. 1" />
                            <x-input-error :messages="$errors->get('house_number')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="type" value="Type" />
                            <select id="type" wire:model="type" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="bedsitter">Bedsitter</option>
                                <option value="one_bedroom">One Bedroom</option>
                                <option value="two_bedroom">Two Bedroom</option>
                                <option value="main_house">Main House</option>
                                <option value="sq">SQ</option>
                                <option value="other">Other</option>
                            </select>
                            <x-input-error :messages="$errors->get('type')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="monthly_rent" value="Monthly Rent (KSh)" />
                            <x-text-input id="monthly_rent" type="number" step="0.01" wire:model="monthly_rent" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('monthly_rent')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deposit_amount" value="Deposit Amount (KSh)" />
                            <x-text-input id="deposit_amount" type="number" step="0.01" wire:model="deposit_amount" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('deposit_amount')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="electricity_arrangement" value="Electricity" />
                            <select id="electricity_arrangement" wire:model="electricity_arrangement" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="separate_meter">Separate Meter</option>
                                <option value="shared">Shared</option>
                                <option value="included">Included in Rent</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="water_arrangement" value="Water" />
                            <select id="water_arrangement" wire:model="water_arrangement" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="shared">Shared</option>
                                <option value="separate_meter">Separate Meter</option>
                                <option value="included">Included in Rent</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="bedrooms" value="Bedrooms (optional)" />
                        <x-text-input id="bedrooms" type="number" wire:model="bedrooms" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('bedrooms')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="status" value="Status" />
                        <select id="status" wire:model="status" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="vacant">Vacant</option>
                            <option value="occupied">Occupied</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Automatically kept in sync when a tenancy starts or ends.</p>
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save House</x-primary-button>
                        <a href="{{ route('manage.houses.index', $block) }}" wire:navigate class="text-sm text-brand-slate hover:text-brand-slate">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
