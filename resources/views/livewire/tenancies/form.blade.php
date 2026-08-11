    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Add Tenant</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <form wire:submit="save" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="property_id" value="Property" />
                            <select id="property_id" wire:model.live="property_id" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="">Select property…</option>
                                @foreach ($properties as $property)
                                    <option value="{{ $property->id }}">{{ $property->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('property_id')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="block_id" value="Block" />
                            <select id="block_id" wire:model.live="block_id" class="mt-1 block w-full rounded-md border-brand-border" @disabled(! $property_id)>
                                <option value="">Select block…</option>
                                @foreach ($blocks as $block)
                                    <option value="{{ $block->id }}">{{ $block->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('block_id')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="house_id" value="Unit" />
                            <select id="house_id" wire:model.live="house_id" class="mt-1 block w-full rounded-md border-brand-border" @disabled(! $property_id)>
                                <option value="">Select vacant unit…</option>
                                @foreach ($availableHouses as $house)
                                    <option value="{{ $house->id }}">{{ $house->house_number }} (KSh {{ number_format($house->monthly_rent) }})</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('house_id')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <x-input-label value="Tenant" />
                            <button type="button" wire:click="$toggle('newTenant')" class="text-xs text-teal hover:underline">
                                {{ $newTenant ? 'Choose existing tenant instead' : '+ New tenant instead' }}
                            </button>
                        </div>

                        @if (! $newTenant)
                            <select wire:model.live="tenant_id" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="">Select existing tenant…</option>
                                @foreach ($availableTenants as $tenant)
                                    <option value="{{ $tenant->id }}">{{ $tenant->name }} ({{ $tenant->phone }})</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tenant_id')" class="mt-1" />
                        @else
                            <div class="grid grid-cols-2 gap-4 mt-2">
                                <div>
                                    <x-text-input type="text" wire:model="new_tenant_name" placeholder="Full name" class="block w-full" />
                                    <x-input-error :messages="$errors->get('new_tenant_name')" class="mt-1" />
                                </div>
                                <div>
                                    <x-text-input type="text" wire:model="new_tenant_phone" placeholder="Phone number" class="block w-full" />
                                    <x-input-error :messages="$errors->get('new_tenant_phone')" class="mt-1" />
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="monthly_rent" value="Monthly Rent (KSh)" />
                            <x-text-input id="monthly_rent" type="number" step="0.01" wire:model="monthly_rent" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('monthly_rent')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="rent_due_day" value="Rent Due Day (of the month)" />
                            <x-text-input id="rent_due_day" type="number" min="1" max="31" wire:model="rent_due_day" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('rent_due_day')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="move_in_date" value="Tenancy Start Date" />
                            <x-text-input id="move_in_date" type="date" wire:model="move_in_date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('move_in_date')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="lease_end_date" value="Tenancy End Date (optional, for fixed-term leases)" />
                            <x-text-input id="lease_end_date" type="date" wire:model="lease_end_date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('lease_end_date')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="notice_period_days" value="Notice Period (days, optional)" />
                            <x-text-input id="notice_period_days" type="number" min="0" max="365" wire:model="notice_period_days" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('notice_period_days')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="deposit_required" value="Deposit Required (KSh)" />
                            <x-text-input id="deposit_required" type="number" step="0.01" wire:model="deposit_required" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('deposit_required')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="initial_deposit_paid" value="Deposit Paid Now (optional)" />
                            <x-text-input id="initial_deposit_paid" type="number" step="0.01" wire:model="initial_deposit_paid" class="mt-1 block w-full" />
                        </div>
                    </div>

                    @if ($initial_deposit_paid)
                        <div>
                            <x-input-label for="deposit_method" value="Deposit Payment Method" />
                            <select id="deposit_method" wire:model="deposit_method" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="mpesa">M-Pesa</option>
                                <option value="bank">Bank</option>
                                <option value="cash">Cash</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    @endif

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Add Tenant</x-primary-button>
                        <a href="{{ route('manage.tenants.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-brand-slate">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
