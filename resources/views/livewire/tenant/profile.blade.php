    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Profile</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                <form wire:submit="save" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-charcoal">Contact Information</h3>
                        <a href="{{ route('profile') }}" wire:navigate class="text-xs text-teal hover:underline">Change your name or password</a>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="phone" value="Phone Number" />
                            <x-text-input id="phone" type="text" wire:model="phone" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="email" value="Email Address" />
                            <x-text-input id="email" type="email" wire:model="email" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('email')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="emergency_contact_name" value="Emergency Contact" />
                            <x-text-input id="emergency_contact_name" type="text" wire:model="emergency_contact_name" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="emergency_contact_phone" value="Emergency Contact Phone" />
                            <x-text-input id="emergency_contact_phone" type="text" wire:model="emergency_contact_phone" class="mt-1 block w-full" />
                        </div>
                    </div>

                    <x-primary-button type="submit">Save Changes</x-primary-button>
                </form>

                @if ($tenancy)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6">
                        <h3 class="text-sm font-semibold text-charcoal mb-3">Tenancy Information (managed by your landlord)</h3>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div><p class="text-brand-slate">Property</p><p class="font-medium text-charcoal">{{ $tenancy->house->block->property->name }}</p></div>
                            <div><p class="text-brand-slate">Unit</p><p class="font-medium text-charcoal">Block {{ $tenancy->house->block->name }} — {{ $tenancy->house->house_number }}</p></div>
                            <div><p class="text-brand-slate">Monthly Rent</p><p class="font-medium text-charcoal">KSh {{ number_format($tenancy->monthly_rent, 2) }}</p></div>
                            <div><p class="text-brand-slate">Deposit</p><p class="font-medium text-charcoal">KSh {{ number_format($tenancy->deposit_required, 2) }}</p></div>
                        </div>
                        <p class="text-xs text-brand-slate mt-3">
                            To change tenancy details, use <a href="{{ route('tenant.tenancy') }}" wire:navigate class="text-teal hover:underline">Request Change</a> on My Tenancy.
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </div>
