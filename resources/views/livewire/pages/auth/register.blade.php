<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $register_as_landlord = false;

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['status'] = $this->register_as_landlord ? 'inactive' : 'active';

        event(new Registered($user = User::create($validated)));

        if ($this->register_as_landlord) {
            $user->assignRole('landlord');
        }

        Auth::login($user);

        $this->redirect(
            $this->register_as_landlord ? route('pending-approval', absolute: false) : route('dashboard', absolute: false),
            navigate: true
        );
    }
}; ?>

<div>
    <form wire:submit="register">
        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input wire:model="password" id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <!-- Register as landlord -->
        <div class="mt-4 flex items-start gap-2">
            <input wire:model="register_as_landlord" id="register_as_landlord" type="checkbox" class="mt-0.5 rounded border-brand-border text-teal focus:ring-teal" />
            <label for="register_as_landlord" class="text-sm text-charcoal">
                I'm a landlord and want to list my properties on Kats Agencies.
                <span class="block text-xs text-brand-slate">Landlord accounts are reviewed by an administrator before activation.</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-brand-slate hover:text-charcoal rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal" href="{{ route('login') }}" wire:navigate>
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>

    <p class="text-center text-sm mt-6">
        <a href="{{ url('/') }}" wire:navigate class="text-brand-slate hover:text-charcoal">← {{ __('Back to home') }}</a>
    </p>
</div>
