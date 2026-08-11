    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $property ? 'Edit Property' : 'Add Property' }}</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
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

                    <div>
                        <x-input-label for="name" value="Property Name" />
                        <x-text-input id="name" type="text" wire:model="name" class="mt-1 block w-full" placeholder="e.g. Mbungoni Bombolulu" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="address" value="Address / Location" />
                        <x-text-input id="address" type="text" wire:model="address" class="mt-1 block w-full" placeholder="e.g. Bombolulu, Mombasa" />
                        <x-input-error :messages="$errors->get('address')" class="mt-1" />
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

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Property</x-primary-button>
                        <a href="{{ route('manage.properties.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-brand-slate">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
