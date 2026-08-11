    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Settings</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif

            <div class="flex gap-2 flex-wrap border-b border-brand-border">
                @foreach (['payment_gateway' => 'Payment Gateway', 'reminders' => 'Rent Reminders', 'financial' => 'Financial Start Date'] as $value => $label)
                    <button wire:click="$set('tab', '{{ $value }}')" class="px-3 py-2 text-sm border-b-2 {{ $tab === $value ? 'border-navy text-navy font-medium' : 'border-transparent text-brand-slate hover:text-charcoal' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'payment_gateway')
                <form wire:submit="saveGateway" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5 max-w-xl">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-charcoal">M-Pesa Collection</h3>
                        <x-status-badge :status="$gatewayConfigured ? 'active' : 'inactive'" />
                    </div>
                    <p class="text-xs text-brand-slate">Connect your till or paybill number so rent can eventually be collected via M-Pesa STK push. This saves your details securely; live payment collection is not yet connected.</p>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="mpesa_shortcode_type" value="Type" />
                            <select id="mpesa_shortcode_type" wire:model="mpesa_shortcode_type" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="paybill">Paybill</option>
                                <option value="till">Till Number</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="mpesa_shortcode" value="Shortcode" />
                            <x-text-input id="mpesa_shortcode" type="text" wire:model="mpesa_shortcode" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('mpesa_shortcode')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="mpesa_consumer_key" value="Daraja Consumer Key" />
                        <x-text-input id="mpesa_consumer_key" type="password" wire:model="mpesa_consumer_key" class="mt-1 block w-full" placeholder="Leave blank to keep existing value" />
                    </div>
                    <div>
                        <x-input-label for="mpesa_consumer_secret" value="Daraja Consumer Secret" />
                        <x-text-input id="mpesa_consumer_secret" type="password" wire:model="mpesa_consumer_secret" class="mt-1 block w-full" placeholder="Leave blank to keep existing value" />
                    </div>
                    <div>
                        <x-input-label for="mpesa_passkey" value="Daraja Passkey" />
                        <x-text-input id="mpesa_passkey" type="password" wire:model="mpesa_passkey" class="mt-1 block w-full" placeholder="Leave blank to keep existing value" />
                    </div>

                    <x-primary-button type="submit">Save Payment Gateway Settings</x-primary-button>
                </form>
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
                            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="reminder_channels" value="sms" class="rounded border-brand-border text-navy"> SMS</label>
                        </div>
                        <x-input-error :messages="$errors->get('reminder_channels')" class="mt-1" />
                    </div>

                    <x-primary-button type="submit">Save Reminder Settings</x-primary-button>
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
        </div>
    </div>
