<?php

use App\Mail\LandlordApplicationReceivedMail;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $national_id = '';
    public string $business_name = '';
    public string $property_location = '';
    public string $intended_unit_count = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Handle an incoming registration request.
     *
     * This form only ever creates landlord accounts — tenants and managers
     * always arrive through an invitation link, never self-registration.
     * The extra details below (beyond name/email/password) exist purely as
     * a basis for the admin's approve/reject decision on the Applications
     * review page — they're not used anywhere else in the app.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone' => ['required', 'string', 'max:20'],
            'national_id' => ['required', 'string', 'max:50'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'property_location' => ['required', 'string', 'max:255'],
            'intended_unit_count' => ['required', 'integer', 'min:1'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['status'] = 'inactive';

        event(new Registered($user = User::create($validated)));

        $user->assignRole('landlord');

        $notifications = app(NotificationService::class);

        foreach (User::role('admin')->get() as $admin) {
            $notifications->notify(
                $admin,
                'landlord_application_submitted',
                'New Landlord Application',
                "{$user->name} applied to list {$user->intended_unit_count} unit(s) in {$user->property_location}.",
                ['in_app'],
                $user,
            );
        }

        Mail::to($user->email)->send(new LandlordApplicationReceivedMail($user->name));

        Auth::login($user);

        $this->redirect(route('pending-approval', absolute: false), navigate: true);
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

        <!-- Phone -->
        <div class="mt-4">
            <x-input-label for="phone" :value="__('Phone number')" />
            <x-text-input wire:model="phone" id="phone" class="block mt-1 w-full" type="tel" name="phone" required autocomplete="tel" />
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        <!-- National ID -->
        <div class="mt-4">
            <x-input-label for="national_id" :value="__('National ID / Passport number')" />
            <x-text-input wire:model="national_id" id="national_id" class="block mt-1 w-full" type="text" name="national_id" required />
            <x-input-error :messages="$errors->get('national_id')" class="mt-2" />
        </div>

        <!-- Business name -->
        <div class="mt-4">
            <x-input-label for="business_name" :value="__('Business / agency name (optional)')" />
            <x-text-input wire:model="business_name" id="business_name" class="block mt-1 w-full" type="text" name="business_name" />
            <x-input-error :messages="$errors->get('business_name')" class="mt-2" />
        </div>

        <!-- Property location -->
        <div class="mt-4">
            <x-input-label for="property_location" :value="__('Where are the properties you plan to list?')" />
            <x-text-input wire:model="property_location" id="property_location" class="block mt-1 w-full" type="text" name="property_location" placeholder="e.g. Nyali, Mombasa" required />
            <x-input-error :messages="$errors->get('property_location')" class="mt-2" />
        </div>

        <!-- Intended unit count -->
        <div class="mt-4">
            <x-input-label for="intended_unit_count" :value="__('How many units do you intend to list?')" />
            <x-text-input wire:model="intended_unit_count" id="intended_unit_count" class="block mt-1 w-full" type="number" min="1" name="intended_unit_count" required />
            <x-input-error :messages="$errors->get('intended_unit_count')" class="mt-2" />
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

        <p class="mt-4 text-xs text-brand-slate">
            This creates a landlord account for listing your properties on Kats Agencies. New accounts are reviewed by an administrator before activation.
            Tenants and managers are set up by invitation instead.
        </p>

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
