    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Maintenance &amp; Complaints</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <form wire:submit="submit" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">

                    <div>
                        <x-input-label for="category" value="Category" />
                        <select id="category" wire:model="category" class="mt-1 block w-full rounded-md border-brand-border">
                            @foreach (\App\Livewire\Tenant\Maintenance\Create::CATEGORIES as $option)
                                <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('category')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="subject" value="Subject" />
                        <x-text-input id="subject" type="text" wire:model="subject" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('subject')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" wire:model="description" rows="4" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label value="Priority" />
                        <div class="mt-1 flex gap-4 text-sm flex-wrap">
                            @foreach (['low' => 'Low', 'normal' => 'Normal', 'urgent' => 'Urgent', 'emergency' => 'Emergency'] as $value => $label)
                                <label class="flex items-center gap-1.5">
                                    <input type="radio" wire:model.live="priority" value="{{ $value }}" class="border-brand-border">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('priority')" class="mt-1" />
                        @if ($priority === 'emergency')
                            <div class="mt-2 text-xs text-warning bg-warning/5 border border-warning/30 rounded-md px-3 py-2">
                                If this is a fire, gas leak, or an immediate danger to anyone's safety, call emergency services first.
                                Marking this as an emergency notifies your landlord immediately by email as well as in the app.
                            </div>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="photo" value="Photo / Document (optional)" />
                        <input type="file" id="photo" wire:model="photo" class="mt-1 block w-full text-sm">
                        <div wire:loading wire:target="photo" class="text-xs text-brand-slate mt-1">Uploading…</div>
                        <x-input-error :messages="$errors->get('photo')" class="mt-1" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Submit Request</x-primary-button>
                        <a href="{{ route('tenant.maintenance.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">View My Requests</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
