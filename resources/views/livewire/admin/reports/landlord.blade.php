    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Landlord Reports</h2>
        </x-slot>

        <div class="p-6">
            <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 max-w-xl space-y-4">
                <div>
                    <x-input-label for="landlordId" value="Select Landlord" />
                    <select id="landlordId" wire:model="landlordId" class="mt-1 block w-full rounded-md border-brand-border">
                        <option value="">Choose a landlord…</option>
                        @foreach ($landlords as $landlord)
                            <option value="{{ $landlord->id }}">{{ $landlord->name }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($landlordId)
                    <button wire:click="export" class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-navy text-white text-xs sm:text-sm font-medium rounded-md hover:bg-navy-dark">
                        Export Property/Unit Report (CSV)
                    </button>
                @endif
            </div>
        </div>
    </div>
