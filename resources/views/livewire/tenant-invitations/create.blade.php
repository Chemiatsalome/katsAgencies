    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Invite Tenant</h2>
                <a href="{{ route('manage.tenant-invitations.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">
                    View all invitations
                </a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

                <div class="flex items-center gap-2 mb-6 text-xs font-medium">
                    <span class="px-2 py-1 rounded-full {{ $step >= 1 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">1. Unit</span>
                    <span class="text-brand-slate">—</span>
                    <span class="px-2 py-1 rounded-full {{ $step >= 2 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">2. Tenancy Terms</span>
                    <span class="text-brand-slate">—</span>
                    <span class="px-2 py-1 rounded-full {{ $step >= 3 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">3. Send Invitation</span>
                </div>

                @if ($step === 1)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
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
                                <x-input-label for="house_id" value="Available Unit" />
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
                            <label class="flex items-center gap-2 text-sm text-brand-slate">
                                <input type="checkbox" wire:model.live="forExistingTenant" class="rounded border-brand-border">
                                This is for a tenant who already has an account (moving to a new/second unit)
                            </label>
                            @if ($forExistingTenant)
                                <select wire:model="existing_tenant_id" class="mt-2 block w-full rounded-md border-brand-border">
                                    <option value="">Select existing tenant…</option>
                                    @foreach ($availableTenants as $tenant)
                                        <option value="{{ $tenant->id }}">{{ $tenant->name }} ({{ $tenant->phone }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('existing_tenant_id')" class="mt-1" />
                            @endif
                        </div>

                        <div class="flex items-center gap-3">
                            <x-primary-button wire:click="goToStep2" type="button">Next: Tenancy Terms</x-primary-button>
                            <a href="{{ route('manage.tenants.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                        </div>
                    </div>
                @elseif ($step === 2)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                        @if ($selectedHouse)
                            <p class="text-sm text-brand-slate">
                                {{ $selectedHouse->block->property->name }} — Block {{ $selectedHouse->block->name }} — Unit {{ $selectedHouse->house_number }}
                            </p>
                        @endif

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="monthly_rent" value="Monthly Rent (KSh)" />
                                <x-text-input id="monthly_rent" type="number" step="0.01" wire:model="monthly_rent" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('monthly_rent')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="deposit_required" value="Deposit (KSh)" />
                                <x-text-input id="deposit_required" type="number" step="0.01" wire:model="deposit_required" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('deposit_required')" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="rent_due_day" value="Rent Due Day (of the month)" />
                                <x-text-input id="rent_due_day" type="number" min="1" max="31" wire:model="rent_due_day" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('rent_due_day')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="notice_period_days" value="Notice Period (days, optional)" />
                                <x-text-input id="notice_period_days" type="number" min="0" max="365" wire:model="notice_period_days" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('notice_period_days')" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="move_in_date" value="Tenancy Start Date" />
                                <x-text-input id="move_in_date" type="date" wire:model="move_in_date" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('move_in_date')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="lease_end_date" value="Tenancy End Date (optional)" />
                                <x-text-input id="lease_end_date" type="date" wire:model="lease_end_date" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('lease_end_date')" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 pt-2 border-t border-brand-border">
                            <div>
                                <x-input-label for="tenant_phone" value="Tenant Phone (optional, prefills SMS/WhatsApp share)" />
                                <x-text-input id="tenant_phone" type="text" wire:model="tenant_phone" class="mt-1 block w-full" />
                            </div>
                            <div>
                                <x-input-label for="tenant_email" value="Tenant Email (optional, prefills email share)" />
                                <x-text-input id="tenant_email" type="email" wire:model="tenant_email" class="mt-1 block w-full" />
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <x-primary-button wire:click="generate" type="button">Generate Registration Link</x-primary-button>
                            <button type="button" wire:click="backToStep1" class="text-sm text-brand-slate hover:text-charcoal">Back</button>
                        </div>
                    </div>
                @elseif ($step === 3 && $invitation)
                    @php
                        $link = route('invitations.accept', $invitation->token);
                        $shareText = "You're invited to register as a tenant at {$invitation->house->block->property->name}, Block {$invitation->house->block->name}, Unit {$invitation->house->house_number}. Complete your registration: {$link}";
                    @endphp
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5" x-data="{ copied: false }">
                        <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">
                            Invitation created. Expires in 7 days.
                        </div>

                        <div class="text-sm text-brand-slate space-y-1">
                            <p><span class="font-medium text-charcoal">Property:</span> {{ $invitation->house->block->property->name }}</p>
                            <p><span class="font-medium text-charcoal">Block:</span> {{ $invitation->house->block->name }}</p>
                            <p><span class="font-medium text-charcoal">Unit:</span> {{ $invitation->house->house_number }}</p>
                            <p><span class="font-medium text-charcoal">Monthly Rent:</span> KSh {{ number_format($invitation->monthly_rent, 2) }}</p>
                            <p><span class="font-medium text-charcoal">Rent Due:</span> {{ $invitation->rent_due_day }}{{ match(true) { in_array($invitation->rent_due_day, [1,21,31]) => 'st', in_array($invitation->rent_due_day, [2,22]) => 'nd', in_array($invitation->rent_due_day, [3,23]) => 'rd', default => 'th' } }} of every month</p>
                        </div>

                        <div>
                            <x-input-label value="Invitation Link" />
                            <div class="mt-1 flex items-center gap-2">
                                <input type="text" readonly value="{{ $link }}" class="block w-full rounded-md border-brand-border text-sm bg-gray-50" x-ref="linkInput" onclick="this.select()">
                                <button type="button" class="shrink-0 px-3 py-2 text-sm rounded-md border border-brand-border hover:bg-gray-50"
                                        @click="navigator.clipboard.writeText($refs.linkInput.value); copied = true; setTimeout(() => copied = false, 2000)">
                                    <span x-show="!copied">Copy</span>
                                    <span x-show="copied" x-cloak>Copied!</span>
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <a href="mailto:{{ $invitation->tenant_email }}?subject={{ urlencode('Tenant Registration Invitation') }}&body={{ urlencode($shareText) }}" class="px-3 py-2 rounded-md border border-brand-border hover:bg-gray-50">Send via Email</a>
                            <a href="sms:{{ $invitation->tenant_phone }}?body={{ urlencode($shareText) }}" class="px-3 py-2 rounded-md border border-brand-border hover:bg-gray-50">Send via SMS</a>
                            <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', (string) $invitation->tenant_phone) }}?text={{ urlencode($shareText) }}" target="_blank" rel="noopener" class="px-3 py-2 rounded-md border border-brand-border hover:bg-gray-50">Share via WhatsApp</a>
                        </div>

                        <div class="flex items-center gap-3 pt-3 border-t border-brand-border">
                            <a href="{{ route('manage.tenant-invitations.index') }}" wire:navigate class="text-sm text-teal hover:underline">View all invitations</a>
                            <a href="{{ route('manage.tenant-invitations.create') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Invite another tenant</a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
