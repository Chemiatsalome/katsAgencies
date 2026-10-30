<div>
    @if ($tenant->hasPortalAccess())
        <div class="text-center space-y-2 py-6">
            <h1 class="text-lg font-semibold text-charcoal">This account has already been set up</h1>
            <p class="text-sm text-brand-slate">
                <a href="{{ route('login') }}" wire:navigate class="text-teal hover:underline">Log in instead</a>
            </p>
        </div>
    @else
        <h1 class="text-lg font-semibold text-charcoal mb-1">Set up your tenant portal account</h1>
        <p class="text-sm text-brand-slate mb-4">
            Hi {{ $tenant->name }} — your landlord has invited you to track your rent and payments online.
            Just choose a password to get started.
        </p>

        <form wire:submit="register" class="space-y-4">
            <div>
                <x-input-label for="email" value="Email Address" />
                <x-text-input id="email" type="email" wire:model="email" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="password" value="Password" />
                <x-text-input id="password" type="password" wire:model="password" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="password_confirmation" value="Confirm Password" />
                <x-text-input id="password_confirmation" type="password" wire:model="password_confirmation" class="mt-1 block w-full" />
            </div>

            <x-primary-button type="submit" class="w-full justify-center">Create My Account</x-primary-button>
        </form>
    @endif
</div>
