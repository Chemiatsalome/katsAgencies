    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Settings</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif

            <div class="flex gap-2 flex-wrap border-b border-brand-border">
                @foreach (['payout' => 'Payout Destination', 'reminders' => 'Rent Reminders', 'late_fees' => 'Late Fees', 'financial' => 'Financial Start Date', 'contract_letterhead' => 'Contract Letterhead', 'renewals' => 'Tenancy Renewals'] as $value => $label)
                    <button wire:click="$set('tab', '{{ $value }}')" class="px-3 py-2 text-sm border-b-2 {{ $tab === $value ? 'border-navy text-navy font-medium' : 'border-transparent text-brand-slate hover:text-charcoal' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'payout')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 max-w-xl space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">How Rent Collection Works</h3>
                    <p class="text-xs text-brand-slate">There are two ways to get paid. Money is sensitive, so here's exactly how each one works.</p>

                    <div class="rounded-md border border-brand-border p-3">
                        <p class="text-xs font-semibold text-charcoal">1. Kats Paybill (the default)</p>
                        <p class="text-xs text-brand-slate mt-1">
                            Tenant M-Pesa payments land in Kats Agencies' own paybill first, and we pay the total out to you by the
                            10th of each month. No setup needed. This is completely safe to use for as long as you like.
                        </p>
                    </div>

                    <div class="rounded-md border border-brand-border p-3">
                        <p class="text-xs font-semibold text-charcoal">2. Direct Collection (optional)</p>
                        <p class="text-xs text-brand-slate mt-1">
                            Tenant M-Pesa payments go straight into your own account instead, automatically. Kats never holds the
                            money, so there's nothing to wait for on the 10th.
                        </p>
                        <p class="text-xs text-brand-slate mt-1">
                            This needs Safaricom's approval first: register "Lipa Na M-Pesa Online" for your till or paybill, which
                            gives you a passkey. That approval is Safaricom's process, not ours, and can take a few days. There's no
                            rush. Keep using the Kats Paybill option above until it comes through, then enter your shortcode and
                            passkey below to switch.
                        </p>
                    </div>

                    <form wire:submit="savePayout" class="border-t border-brand-border pt-4 space-y-4">
                        <div class="flex items-center justify-between">
                            <x-input-label value="Your Setup" />
                            <x-status-badge :status="$directCollectionConfigured ? 'active' : 'inactive'" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="payout_shortcode_type" value="Type" />
                                <select id="payout_shortcode_type" wire:model="payout_shortcode_type" class="mt-1 block w-full rounded-md border-brand-border">
                                    <option value="paybill">Paybill</option>
                                    <option value="till">Till Number</option>
                                </select>
                            </div>
                            <div>
                                <x-input-label for="payout_shortcode" value="Shortcode" />
                                <x-text-input id="payout_shortcode" type="text" wire:model="payout_shortcode" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('payout_shortcode')" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="mpesa_passkey" value="Daraja Passkey (optional, only for Direct Collection)" />
                            <x-text-input id="mpesa_passkey" type="password" wire:model="mpesa_passkey" class="mt-1 block w-full" placeholder="{{ $directCollectionConfigured ? 'Leave blank to keep existing value' : 'Not set, so rent goes through Kats\' paybill' }}" />
                            <x-input-error :messages="$errors->get('mpesa_passkey')" class="mt-1" />
                            @if ($directCollectionConfigured)
                                <button type="button" wire:click="disableDirectCollection" wire:confirm="Switch back to collecting rent through Kats' paybill instead? You'll need to re-enter your passkey to turn direct collection back on later." class="mt-2 text-xs text-danger hover:underline">
                                    Turn off direct collection
                                </button>
                            @endif
                        </div>

                        <x-primary-button type="submit">Save Payout Destination</x-primary-button>
                    </form>
                </div>
            @endif

            @if ($tab === 'reminders')
                <form wire:submit="saveReminders" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5 max-w-xl">
                    <h3 class="text-sm font-semibold text-charcoal">Rent Reminder Schedule</h3>
                    <p class="text-xs text-brand-slate">Comma-separated day counts. These control when automated rent reminders go out to tenants.</p>

                    <div>
                        <x-input-label for="reminder_days_before" value="Days Before Due Date" />
                        <x-text-input id="reminder_days_before" type="text" wire:model="reminder_days_before" class="mt-1 block w-full" placeholder="e.g. 7,3,1" />
                        <x-input-error :messages="$errors->get('reminder_days_before')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="reminder_days_after" value="Days After Due Date (overdue reminders)" />
                        <x-text-input id="reminder_days_after" type="text" wire:model="reminder_days_after" class="mt-1 block w-full" placeholder="e.g. 1,3,7,14,30" />
                        <x-input-error :messages="$errors->get('reminder_days_after')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label value="Channels" />
                        <div class="mt-1 flex gap-4 text-sm">
                            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="reminder_channels" value="in_app" class="rounded border-brand-border text-navy"> In-App</label>
                            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="reminder_channels" value="email" class="rounded border-brand-border text-navy"> Email</label>
                        </div>
                        <x-input-error :messages="$errors->get('reminder_channels')" class="mt-1" />
                    </div>

                    <x-primary-button type="submit">Save Reminder Settings</x-primary-button>
                </form>
            @endif

            @if ($tab === 'late_fees')
                <form wire:submit="saveLateFees" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5 max-w-xl">
                    <h3 class="text-sm font-semibold text-charcoal">Late Fees</h3>
                    <p class="text-xs text-brand-slate">
                        When enabled, a one-time late fee is added automatically to a tenant's rent charge once it's overdue past the grace period below.
                        A tenant on a custom due date they specifically requested gets no grace period — the fee applies the day after that date if unpaid.
                        Everyone else (the standard case) gets the grace period.
                    </p>

                    <label class="flex items-center gap-2 text-sm text-charcoal">
                        <input type="checkbox" wire:model="late_fee_enabled" class="rounded border-brand-border" />
                        <span>Enable automatic late fees</span>
                    </label>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="late_fee_percentage" value="Late Fee (%)" />
                            <x-text-input id="late_fee_percentage" type="number" step="0.1" min="0" max="100" wire:model="late_fee_percentage" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('late_fee_percentage')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="late_fee_grace_days" value="Grace Period (days)" />
                            <x-text-input id="late_fee_grace_days" type="number" step="1" min="0" max="60" wire:model="late_fee_grace_days" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('late_fee_grace_days')" class="mt-1" />
                            <p class="text-xs text-brand-slate mt-1">Only applies to tenants on the standard due date — not those who requested their own.</p>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="default_rent_due_day" value="Default Rent Due Day" />
                        <x-text-input id="default_rent_due_day" type="number" step="1" min="1" max="31" wire:model="default_rent_due_day" class="mt-1 block w-full max-w-[8rem]" />
                        <x-input-error :messages="$errors->get('default_rent_due_day')" class="mt-1" />
                        <p class="text-xs text-brand-slate mt-1">Pre-filled whenever you set up a new tenancy, unless you change it for that specific tenant. If you never set this, the system defaults to the 5th.</p>
                    </div>

                    <x-primary-button type="submit">Save Late Fee Settings</x-primary-button>
                </form>
            @endif

            @if ($tab === 'financial')
                <form wire:submit="saveFinancial" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5 max-w-xl">
                    <h3 class="text-sm font-semibold text-charcoal">Financial Start Date</h3>
                    <p class="text-xs text-brand-slate">
                        Rent before this date is treated as historical. If a historical month has no payment record, it's assumed settled rather than counted as arrears — you can review and correct individual months later from Historical Rent Review. From this date onward, missing rent is tracked as real outstanding balance.
                    </p>

                    <div>
                        <x-input-label for="financial_start_date" value="Financial Start Date" />
                        <x-text-input id="financial_start_date" type="date" wire:model="financial_start_date" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('financial_start_date')" class="mt-1" />
                    </div>

                    <x-primary-button type="submit">Save Financial Start Date</x-primary-button>
                </form>
            @endif

            @if ($tab === 'contract_letterhead')
                <form wire:submit="saveContractLetterhead" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5 max-w-xl">
                    <h3 class="text-sm font-semibold text-charcoal">Contract Letterhead</h3>
                    <p class="text-xs text-brand-slate">
                        Used to fill in the system contract template's complaints/managing-agency details. Your own name, phone, email and postal address as the lessor are pulled from your account automatically — these fields are only for the agency/supervisor contact shown to tenants.
                    </p>

                    <div>
                        <x-input-label for="contract_agency_name" value="Managing Agency / Company Name" />
                        <x-text-input id="contract_agency_name" type="text" wire:model="contract_agency_name" class="mt-1 block w-full" placeholder="e.g. Kat's Housing Agency" />
                        <x-input-error :messages="$errors->get('contract_agency_name')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="contract_supervisor_name" value="Complaints Supervisor Name" />
                            <x-text-input id="contract_supervisor_name" type="text" wire:model="contract_supervisor_name" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('contract_supervisor_name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="contract_supervisor_phone" value="Supervisor Phone" />
                            <x-text-input id="contract_supervisor_phone" type="text" wire:model="contract_supervisor_phone" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('contract_supervisor_phone')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="contract_drawn_by_name" value="Agreement Drawn By (optional)" />
                        <x-text-input id="contract_drawn_by_name" type="text" wire:model="contract_drawn_by_name" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('contract_drawn_by_name')" class="mt-1" />
                    </div>

                    <p class="text-xs text-brand-slate">
                        Need to update your own name, phone, email or address as the lessor? Edit those from <a href="{{ route('profile') }}" wire:navigate class="text-teal hover:underline">your profile</a>.
                    </p>

                    <x-primary-button type="submit">Save Contract Letterhead</x-primary-button>
                </form>
            @endif

            @if ($tab === 'renewals')
                <form wire:submit="saveRenewalPolicy" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5 max-w-xl">
                    <h3 class="text-sm font-semibold text-charcoal">Tenancy Renewal Review Cycle</h3>
                    <p class="text-xs text-brand-slate">
                        For a fixed-term tenancy, renewal becomes relevant about a month before the agreement's own end date. For an open-ended tenancy with no end date, this setting decides how often a periodic renewal review becomes due, counted from the tenancy's start date (or its last approved renewal).
                    </p>

                    <div>
                        <x-input-label for="renewal_review_cycle_years" value="Review Cycle for Open-Ended Tenancies" />
                        <select id="renewal_review_cycle_years" wire:model="renewal_review_cycle_years" class="mt-1 block w-full max-w-xs rounded-md border-brand-border text-sm">
                            <option value="1">Every 1 year</option>
                            <option value="2">Every 2 years (default)</option>
                        </select>
                        <x-input-error :messages="$errors->get('renewal_review_cycle_years')" class="mt-1" />
                    </div>

                    <p class="text-xs text-brand-slate">
                        This only schedules a reminder and makes the renewal request option available — it never changes an existing agreement's terms by itself.
                    </p>

                    <x-primary-button type="submit">Save Renewal Policy</x-primary-button>
                </form>
            @endif
        </div>
    </div>
