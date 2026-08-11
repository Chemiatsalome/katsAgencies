    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $schedule ? 'Edit' : 'New' }} Preventive Maintenance Schedule</h2>
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

                <div>
                    <x-input-label for="title" value="Title" />
                    <x-text-input id="title" type="text" wire:model="title" class="mt-1 block w-full" placeholder="e.g. Gutter Cleaning" />
                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="category" value="Category" />
                        <x-text-input id="category" type="text" wire:model="category" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('category')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="frequency_months" value="Repeats Every (months)" />
                        <x-text-input id="frequency_months" type="number" min="1" max="60" wire:model="frequency_months" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('frequency_months')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="next_due_date" value="Next Due Date" />
                    <x-text-input id="next_due_date" type="date" wire:model="next_due_date" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('next_due_date')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="notes" value="Notes (optional)" />
                    <textarea id="notes" wire:model="notes" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                </div>

                <label class="flex items-center gap-2 text-sm text-charcoal">
                    <input type="checkbox" wire:model="is_active" class="rounded border-brand-border text-navy">
                    Active
                </label>

                <div class="flex items-center gap-3">
                    <x-primary-button type="submit">Save Schedule</x-primary-button>
                    <a href="{{ route('manage.maintenance.schedules.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                </div>
            </form>
        </div>
    </div>
