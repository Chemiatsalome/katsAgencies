    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $tenant ? 'Edit Tenant' : 'Add Tenant' }}</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <form wire:submit="save" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
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

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="email" value="Email (optional)" />
                            <x-text-input id="email" type="email" wire:model="email" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('email')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="id_number" value="ID / Passport No. (optional)" />
                            <x-text-input id="id_number" type="text" wire:model="id_number" class="mt-1 block w-full" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="emergency_contact_name" value="Emergency Contact Name" />
                            <x-text-input id="emergency_contact_name" type="text" wire:model="emergency_contact_name" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="emergency_contact_phone" value="Emergency Contact Phone" />
                            <x-text-input id="emergency_contact_phone" type="text" wire:model="emergency_contact_phone" class="mt-1 block w-full" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="payment_due_day" value="Rent Due Day of Month" />
                        <x-text-input id="payment_due_day" type="number" min="1" max="31" wire:model="payment_due_day" class="mt-1 block w-32" />
                        <x-input-error :messages="$errors->get('payment_due_day')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="notes" value="Notes" />
                        <textarea id="notes" wire:model="notes" rows="2" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                    </div>

                    @if (! $tenant?->user_id)
                        <div class="border-t border-brand-border pt-5">
                            <p class="text-sm font-medium text-brand-slate mb-3">Portal login (optional)</p>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="portal_email" value="Login Email" />
                                    <x-text-input id="portal_email" type="email" wire:model="portal_email" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('portal_email')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="portal_password" value="Login Password" />
                                    <x-text-input id="portal_password" type="password" wire:model="portal_password" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('portal_password')" class="mt-1" />
                                </div>
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-brand-slate border-t border-brand-border pt-5">Portal login already linked to this tenant.</p>
                    @endif

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Tenant</x-primary-button>
                        <a href="{{ route('manage.tenants.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-brand-slate">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
