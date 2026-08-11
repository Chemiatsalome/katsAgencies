    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Log Application</h2>
        </x-slot>

        <div class="p-6">
            <form wire:submit="save" class="max-w-xl bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                <div>
                    <x-input-label for="desired_property_id" value="Property" />
                    <select id="desired_property_id" wire:model.live="desired_property_id" class="mt-1 block w-full rounded-md border-brand-border">
                        <option value="">Select property…</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('desired_property_id')" class="mt-1" />
                </div>

                @if ($desired_property_id)
                    <div>
                        <x-input-label for="desired_house_id" value="Desired Unit (optional)" />
                        <select id="desired_house_id" wire:model="desired_house_id" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="">Any vacant unit</option>
                            @foreach ($houses as $house)
                                <option value="{{ $house->id }}">{{ $house->house_number }} — KSh {{ number_format($house->monthly_rent) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="applicant_name" value="Applicant Name" />
                        <x-text-input id="applicant_name" type="text" wire:model="applicant_name" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('applicant_name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="applicant_phone" value="Phone" />
                        <x-text-input id="applicant_phone" type="text" wire:model="applicant_phone" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('applicant_phone')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="applicant_email" value="Email (optional)" />
                        <x-text-input id="applicant_email" type="email" wire:model="applicant_email" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="application_date" value="Application Date" />
                        <x-text-input id="application_date" type="date" wire:model="application_date" class="mt-1 block w-full" />
                    </div>
                </div>

                <div>
                    <x-input-label for="notes" value="Notes" />
                    <textarea id="notes" wire:model="notes" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button type="submit">Save Application</x-primary-button>
                    <a href="{{ route('manage.applications.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                </div>
            </form>
        </div>
    </div>
