<div>
    @php
        $ordinal = fn ($d) => $d.match (true) {
            in_array($d, [1, 21, 31]) => 'st',
            in_array($d, [2, 22]) => 'nd',
            in_array($d, [3, 23]) => 'rd',
            default => 'th',
        };
    @endphp

    @unless ($invitation->isUsable())
        <div class="text-center space-y-2 py-6">
            <h1 class="text-lg font-semibold text-charcoal">
                @if ($invitation->status === 'completed')
                    This invitation has already been used
                @elseif ($invitation->status === 'cancelled')
                    This invitation was cancelled
                @else
                    This invitation has expired
                @endif
            </h1>
            <p class="text-sm text-brand-slate">Please contact your landlord for a new invitation link.</p>
        </div>
    @else
        <h1 class="text-lg font-semibold text-charcoal mb-1">You have been invited to register as a tenant</h1>
        <p class="text-sm text-brand-slate mb-4">These details were set by your landlord and cannot be changed here.</p>

        <div class="grid grid-cols-2 gap-3 text-sm bg-offwhite rounded-md p-4 mb-6">
            <div>
                <div class="text-brand-slate text-xs uppercase tracking-wide">Property</div>
                <div class="text-charcoal font-medium">{{ $invitation->house->block->property->name }}</div>
            </div>
            <div>
                <div class="text-brand-slate text-xs uppercase tracking-wide">Block</div>
                <div class="text-charcoal font-medium">{{ $invitation->house->block->name }}</div>
            </div>
            <div>
                <div class="text-brand-slate text-xs uppercase tracking-wide">Unit</div>
                <div class="text-charcoal font-medium">{{ $invitation->house->house_number }}</div>
            </div>
            <div>
                <div class="text-brand-slate text-xs uppercase tracking-wide">Monthly Rent</div>
                <div class="text-charcoal font-medium">KSh {{ number_format($invitation->monthly_rent, 2) }}</div>
            </div>
            <div>
                <div class="text-brand-slate text-xs uppercase tracking-wide">Rent Due Date</div>
                <div class="text-charcoal font-medium">{{ $ordinal($invitation->rent_due_day) }}</div>
            </div>
            <div>
                <div class="text-brand-slate text-xs uppercase tracking-wide">Move-in Date</div>
                <div class="text-charcoal font-medium">{{ $invitation->move_in_date->format('d M Y') }}</div>
            </div>
        </div>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2 mb-4">{{ session('status') }}</div>
        @endif

        @auth
            @if (auth()->user()->hasRole('tenant') && auth()->user()->tenant)
                <div class="space-y-3">
                    <p class="text-sm text-brand-slate">
                        You're logged in as <span class="font-medium text-charcoal">{{ auth()->user()->name }}</span>.
                    </p>
                    <x-primary-button wire:click="acceptAsCurrentTenant" type="button">Accept Invitation</x-primary-button>
                </div>
            @else
                <div class="text-sm text-brand-slate space-y-2">
                    <p>
                        You're logged in as <span class="font-medium text-charcoal">{{ auth()->user()->email }}</span>, which is not a tenant account.
                        Log out to register or accept as a tenant.
                    </p>
                    <livewire:layout.logout-button />
                </div>
            @endif
        @else
            @if (! $wantsToLogIn)
                <form wire:submit="register" class="space-y-4">
                    <h2 class="text-sm font-semibold text-charcoal">Personal Information</h2>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="name" value="Full Name" />
                            <x-text-input id="name" type="text" wire:model="name" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="phone" value="Phone Number" />
                            <x-text-input id="phone" type="text" wire:model="phone" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="email" value="Email Address" />
                        <x-text-input id="email" type="email" wire:model="email" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="id_number" value="National ID / Passport Number (optional)" />
                        <x-text-input id="id_number" type="text" wire:model="id_number" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('id_number')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="emergency_contact_name" value="Emergency Contact (optional)" />
                            <x-text-input id="emergency_contact_name" type="text" wire:model="emergency_contact_name" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="emergency_contact_phone" value="Emergency Contact Phone (optional)" />
                            <x-text-input id="emergency_contact_phone" type="text" wire:model="emergency_contact_phone" class="mt-1 block w-full" />
                        </div>
                    </div>

                    <h2 class="text-sm font-semibold text-charcoal pt-2 border-t border-brand-border">Account Credentials</h2>

                    <div>
                        <x-input-label for="password" value="Password" />
                        <x-text-input id="password" type="password" wire:model="password" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('password')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="password_confirmation" value="Confirm Password" />
                        <x-text-input id="password_confirmation" type="password" wire:model="password_confirmation" class="mt-1 block w-full" />
                    </div>

                    <x-primary-button type="submit" class="w-full justify-center">Create Account &amp; Submit Registration</x-primary-button>

                    <p class="text-center text-sm text-brand-slate">
                        Already have an account?
                        <button type="button" wire:click="switchToLogin" class="text-teal hover:underline">Log in instead</button>
                    </p>
                </form>
            @else
                <form wire:submit="logInAndAccept" class="space-y-4">
                    <h2 class="text-sm font-semibold text-charcoal">Log In to Accept This Invitation</h2>

                    <div>
                        <x-input-label for="login_email" value="Email" />
                        <x-text-input id="login_email" type="email" wire:model="login_email" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('login_email')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="login_password" value="Password" />
                        <x-text-input id="login_password" type="password" wire:model="login_password" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('login_password')" class="mt-1" />
                    </div>

                    <x-primary-button type="submit" class="w-full justify-center">Log In &amp; Accept Invitation</x-primary-button>

                    <p class="text-center text-sm text-brand-slate">
                        <button type="button" wire:click="switchToRegister" class="text-teal hover:underline">Back to registration</button>
                    </p>
                </form>
            @endif
        @endauth
    @endunless
</div>
