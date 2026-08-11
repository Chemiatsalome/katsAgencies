    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $block ? 'Edit Block' : 'Add Block' }} — {{ $property->name }}</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-lg mx-auto px-4 sm:px-6 lg:px-8">
                <form wire:submit="save" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                    <div>
                        <x-input-label for="name" value="Block Name" />
                        <x-text-input id="name" type="text" wire:model="name" class="mt-1 block w-full" placeholder="e.g. Block A" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Block</x-primary-button>
                        <a href="{{ route('manage.blocks.index', $property) }}" wire:navigate class="text-sm text-brand-slate hover:text-brand-slate">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
