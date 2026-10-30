<div>
    @unless ($manager->isUsable())
        <div class="text-center space-y-2 py-6">
            <h1 class="text-lg font-semibold text-charcoal">
                @if ($manager->status === 'active')
                    This invitation has already been used
                @else
                    This invitation has expired
                @endif
            </h1>
            <p class="text-sm text-brand-slate">Please contact {{ $manager->landlord->name }} for a new invitation link.</p>
        </div>
    @else
        <h1 class="text-lg font-semibold text-charcoal mb-1">You have been invited to manage properties</h1>
        <p class="text-sm text-brand-slate mb-4">{{ $manager->landlord->name }} has invited you to help manage their portfolio on Kats Agencies. Set a password to activate your account.</p>

        <div class="grid grid-cols-2 gap-3 text-sm bg-offwhite rounded-md p-4 mb-6">
            <div>
                <div class="text-brand-slate text-xs uppercase tracking-wide">Name</div>
                <div class="text-charcoal font-medium">{{ $manager->name }}</div>
            </div>
            <div>
                <div class="text-brand-slate text-xs uppercase tracking-wide">Email</div>
                <div class="text-charcoal font-medium">{{ $manager->email }}</div>
            </div>
            <div class="col-span-2">
                <div class="text-brand-slate text-xs uppercase tracking-wide">Property Access</div>
                <div class="text-charcoal font-medium">
                    {{ $manager->all_properties ? 'All properties' : $manager->properties->pluck('name')->join(', ') }}
                </div>
            </div>
        </div>

        <form wire:submit="register" class="space-y-4">
            <div>
                <x-input-label for="password" value="Password" />
                <x-text-input id="password" type="password" wire:model="password" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="password_confirmation" value="Confirm Password" />
                <x-text-input id="password_confirmation" type="password" wire:model="password_confirmation" class="mt-1 block w-full" />
            </div>

            <x-primary-button type="submit" class="w-full justify-center">Activate Account</x-primary-button>
        </form>
    @endunless
</div>
