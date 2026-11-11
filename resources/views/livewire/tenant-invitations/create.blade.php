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
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (! $invitation)
                    <div class="text-sm bg-teal/10 border border-teal/30 text-charcoal rounded-md px-4 py-2">
                        Already have this tenant's own details and they're ready to move in today? <a href="{{ route('manage.tenancies.create') }}" wire:navigate class="text-teal font-medium hover:underline">Add them directly instead →</a>
                        An invitation is for when they'll register themselves.
                    </div>

                    <form wire:submit="generate" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
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
                                <button type="button" wire:click="$toggle('forExistingTenant')" class="text-xs text-teal hover:underline">
                                    {{ $forExistingTenant ? 'This is a new / prospective tenant instead' : 'This is an existing tenant (moving to a new/second unit)' }}
                                </button>
                            </div>

                            @if ($forExistingTenant)
                                <select wire:model.live="existing_tenant_id" class="mt-1 block w-full rounded-md border-brand-border">
                                    <option value="">Select existing tenant…</option>
                                    @foreach ($availableTenants as $tenant)
                                        <option value="{{ $tenant->id }}">{{ $tenant->name }} ({{ $tenant->phone }})</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('existing_tenant_id')" class="mt-1" />

                                @if ($returningTenantHistory->isNotEmpty())
                                    <div class="mt-2 rounded-md bg-warning/10 border border-warning/30 text-charcoal text-sm px-4 py-3 space-y-2">
                                        <p class="font-medium">This tenant already has {{ $returningTenantHistory->count() }} {{ $returningTenantHistory->count() === 1 ? 'tenancy record' : 'tenancy records' }} in the system:</p>
                                        <ul class="list-disc list-inside text-xs text-brand-slate">
                                            @foreach ($returningTenantHistory as $past)
                                                <li>
                                                    {{ $past->house->block->property->name }}, {{ $past->house->house_number }}
                                                    — {{ $past->move_in_date->format('d M Y') }} to {{ $past->move_out_date?->format('d M Y') ?? ($past->lease_end_date?->format('d M Y') ?? 'present') }}
                                                    ({{ ucfirst(str_replace('_', ' ', $past->status)) }})
                                                </li>
                                            @endforeach
                                        </ul>
                                        <label class="flex items-start gap-2 text-sm text-charcoal">
                                            <input type="checkbox" wire:model="acknowledgeReturningTenant" class="mt-0.5 rounded border-warning/40" />
                                            <span>Yes, I want to invite this tenant again.</span>
                                        </label>
                                        <x-input-error :messages="$errors->get('acknowledgeReturningTenant')" class="mt-1" />
                                    </div>
                                @endif
                            @else
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                                    <div>
                                        <x-text-input type="text" wire:model="tenant_phone" placeholder="Phone number (optional)" class="block w-full" />
                                        <x-input-error :messages="$errors->get('tenant_phone')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-text-input type="email" wire:model="tenant_email" placeholder="Email (optional)" class="block w-full" />
                                        <x-input-error :messages="$errors->get('tenant_email')" class="mt-1" />
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-brand-slate">
                                    Not required — only used to prefill the message when you share the invitation link below. The tenant enters their own details when they register.
                                </p>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="move_in_date" value="Tenancy Start Date" />
                                <x-text-input id="move_in_date" type="date" wire:model="move_in_date" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('move_in_date')" class="mt-1" />
                                <p class="mt-1 text-xs text-brand-slate">
                                    This is when the tenancy itself begins — not necessarily the date the tenant actually completes registration.
                                </p>
                            </div>
                            <div>
                                <x-input-label for="lease_end_date" value="Tenancy End Date (optional, for fixed-term leases)" />
                                <x-text-input id="lease_end_date" type="date" wire:model="lease_end_date" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('lease_end_date')" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="notice_period_months" value="Notice Period (optional)" />
                            <p class="mt-1 text-xs text-brand-slate">
                                The amount of notice required — by the tenancy agreement and applicable requirements — before this tenancy ends or the tenant is expected to vacate. When notice is later given, the vacate date is worked out from the notice date plus this period.
                            </p>
                            <select id="notice_period_months" wire:model.live="notice_period_months" class="mt-2 block w-full max-w-xs rounded-md border-brand-border">
                                <option value="">Not specified</option>
                                <option value="1">1 month</option>
                                <option value="2">2 months</option>
                                <option value="3">3 months</option>
                                <option value="6">6 months</option>
                                <option value="12">12 months</option>
                                <option value="custom">Other (enter exact days)</option>
                            </select>

                            @if ($notice_period_months === 'custom')
                                <div class="mt-2 max-w-xs">
                                    <x-input-label for="notice_period_days" value="Notice Period (days)" />
                                    <x-text-input id="notice_period_days" type="number" min="0" max="365" wire:model.live="notice_period_days" class="mt-1 block w-full" />
                                </div>
                            @elseif ($notice_period_months !== '' && $notice_period_days !== '')
                                <p class="mt-2 text-xs text-brand-slate">
                                    That's {{ $notice_period_days }} day(s) from the move-in date above. For example, if notice were given today, the tenant would need to vacate by
                                    <strong class="text-charcoal">{{ now()->addDays((int) $notice_period_days)->format('d M Y') }}</strong>.
                                </p>
                            @endif

                            <x-input-error :messages="$errors->get('notice_period_days')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="deposit_required" value="Deposit Required (KSh)" />
                            <x-text-input id="deposit_required" type="number" step="0.01" wire:model.live="deposit_required" class="mt-1 block w-full max-w-xs" />
                            <x-input-error :messages="$errors->get('deposit_required')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label value="Deposit Paid" />
                            <p class="mt-1 text-xs text-brand-slate">
                                Already collected a deposit from this tenant before inviting them to register (e.g. in cash)? Pick a common amount, or enter the exact figure for a partial payment. Leave this at "No deposit paid" if nothing has changed hands yet.
                            </p>

                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                @foreach ($depositPresetOptions as $preset)
                                    <button
                                        type="button"
                                        wire:click="$set('deposit_option', '{{ $preset['key'] }}')"
                                        class="text-left text-sm rounded-md border px-3 py-2 {{ $deposit_option === $preset['key'] ? 'border-teal bg-teal/5 text-charcoal' : 'border-brand-border text-brand-slate hover:border-teal/40' }}"
                                    >
                                        <span class="block font-medium">{{ $preset['label'] }}</span>
                                        @if ($preset['amount'] !== null)
                                            <span class="block text-xs mt-0.5">KSh {{ number_format($preset['amount']) }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>

                            @if ($deposit_option === 'custom')
                                <div class="mt-3 max-w-xs">
                                    <x-input-label for="initial_deposit_paid" value="Amount Paid (KSh)" />
                                    <x-text-input
                                        id="initial_deposit_paid"
                                        type="number"
                                        step="0.01"
                                        wire:model.live="initial_deposit_paid"
                                        class="mt-1 block w-full [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none [-moz-appearance:textfield]"
                                    />
                                    <x-input-error :messages="$errors->get('initial_deposit_paid')" class="mt-1" />
                                </div>
                            @endif

                            <div class="mt-3 grid grid-cols-3 gap-4 text-sm bg-offwhite rounded-md p-3 max-w-md">
                                <div>
                                    <p class="text-xs text-brand-slate">Required</p>
                                    <p class="font-medium text-charcoal">KSh {{ number_format((float) ($deposit_required ?: 0)) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-brand-slate">Paid</p>
                                    <p class="font-medium text-charcoal">KSh {{ number_format((float) ($initial_deposit_paid ?: 0)) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-brand-slate">Balance</p>
                                    @php $depositBalance = max(0, (float) ($deposit_required ?: 0) - (float) ($initial_deposit_paid ?: 0)); @endphp
                                    <p class="font-medium {{ $depositBalance > 0 ? 'text-warning' : 'text-charcoal' }}">KSh {{ number_format($depositBalance) }}</p>
                                </div>
                            </div>
                        </div>

                        @if ((float) ($initial_deposit_paid ?: 0) > 0)
                            <div>
                                <x-input-label for="deposit_method" value="Deposit Payment Method" />
                                <select id="deposit_method" wire:model="deposit_method" class="mt-1 block w-full max-w-xs rounded-md border-brand-border">
                                    <option value="mpesa">M-Pesa</option>
                                    <option value="bank">Bank</option>
                                    <option value="cash">Cash</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        @endif

                        <div class="flex items-center gap-3">
                            <x-loading-button type="submit" target="generate" loading-text="Generating…">Generate Registration Link</x-loading-button>
                            <a href="{{ route('manage.tenants.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                        </div>
                    </form>
                @else
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
                            @if ((float) $invitation->initial_deposit_paid > 0)
                                <p><span class="font-medium text-charcoal">Deposit Already Paid:</span> KSh {{ number_format($invitation->initial_deposit_paid, 2) }} ({{ $invitation->deposit_method }})</p>
                            @endif
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
