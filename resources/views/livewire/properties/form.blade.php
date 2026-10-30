    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $property ? 'Edit Property' : 'Add Property' }}</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
                @if (! $property)
                    <div class="text-sm bg-teal/10 border border-teal/30 text-charcoal rounded-md px-4 py-2">
                        Adding more than a few properties/units? <a href="{{ route('manage.properties.import') }}" wire:navigate class="text-teal font-medium hover:underline">Import them from a CSV instead →</a>
                    </div>
                @endif

                <form wire:submit="save" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">

                    @if ($landlords->isNotEmpty())
                        <div>
                            <x-input-label for="landlord_id" value="Landlord" />
                            <select id="landlord_id" wire:model="landlord_id" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="">Select landlord…</option>
                                @foreach ($landlords as $landlord)
                                    <option value="{{ $landlord->id }}">{{ $landlord->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('landlord_id')" class="mt-1" />
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="name" value="Property Name" />
                            <x-text-input id="name" type="text" wire:model="name" class="mt-1 block w-full" placeholder="e.g. Mbungoni Bombolulu" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="type" value="Property Type" />
                            <select id="type" wire:model="type" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="apartment">Apartment</option>
                                <option value="residential_compound">Residential Compound</option>
                                <option value="individual_house">Individual House</option>
                                <option value="maisonette">Maisonette</option>
                                <option value="villas">Villas</option>
                                <option value="commercial_building">Commercial Building</option>
                                <option value="mixed_use">Mixed Use</option>
                                <option value="other">Other</option>
                            </select>
                            <x-input-error :messages="$errors->get('type')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-location-autocomplete
                            id="address"
                            label="Address / Location"
                            address-property="address"
                            lat-property="latitude"
                            lon-property="longitude"
                            :initial-address="$address"
                        />
                        <p class="text-xs text-brand-slate mt-1">Pick a suggestion as you type, or just type the address manually — either way is saved.</p>
                        <x-input-error :messages="$errors->get('address')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="photo" value="Property Photo" />

                        @if ($photo)
                            <img src="{{ $photo->temporaryUrl() }}" class="mt-2 h-24 w-24 object-cover rounded-md border border-brand-border" alt="New photo preview">
                            <p class="text-xs text-brand-slate mt-1">New photo selected — save to apply it.</p>
                        @elseif ($property?->photoUrl())
                            <img src="{{ $property->photoUrl() }}" class="mt-2 h-24 w-24 object-cover rounded-md border border-brand-border" alt="Current property photo">
                            <p class="text-xs text-brand-slate mt-1">Current photo. Choose a file below to replace it.</p>
                        @endif

                        <input type="file" id="photo" wire:model="photo" accept="image/*" class="mt-2 block w-full text-sm" />
                        <div wire:loading wire:target="photo" class="text-xs text-brand-slate mt-1">Uploading…</div>
                        <p class="text-xs text-brand-slate mt-1">
                            @if ($property)
                                Leave this blank to keep the current photo.
                            @else
                                Optional — you can always add or change this later.
                            @endif
                        </p>
                        <x-input-error :messages="$errors->get('photo')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="status" value="Status" />
                        <select id="status" wire:model="status" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                    </div>

                    <div class="border-t border-brand-border pt-5">
                        <p class="text-sm font-medium text-charcoal mb-1">Investment &amp; Valuation (optional)</p>
                        <p class="text-xs text-brand-slate mb-3">Used to calculate gross/net yield on the Performance report — leave blank if unknown.</p>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="purchase_price" value="Purchase Price (KSh)" />
                                <x-text-input id="purchase_price" type="number" step="0.01" wire:model="purchase_price" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('purchase_price')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="initial_renovation_cost" value="Initial Renovation Cost (KSh)" />
                                <x-text-input id="initial_renovation_cost" type="number" step="0.01" wire:model="initial_renovation_cost" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('initial_renovation_cost')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="other_acquisition_costs" value="Other Acquisition Costs (KSh)" />
                                <x-text-input id="other_acquisition_costs" type="number" step="0.01" wire:model="other_acquisition_costs" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('other_acquisition_costs')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="current_estimated_value" value="Current Estimated Value (KSh)" />
                                <x-text-input id="current_estimated_value" type="number" step="0.01" wire:model="current_estimated_value" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('current_estimated_value')" class="mt-1" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Property</x-primary-button>
                        <a href="{{ route('manage.properties.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-brand-slate">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
