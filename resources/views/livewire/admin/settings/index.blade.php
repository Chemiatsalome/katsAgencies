    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">System Settings</h2>
        </x-slot>

        <div class="p-6">
            @if (session('status'))
                <div class="mb-4 bg-success/10 border border-success/30 text-charcoal text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
            @endif

            <div class="flex gap-2 mb-6 flex-wrap">
                @foreach (['general' => 'General', 'finance' => 'Finance', 'property' => 'Property', 'payments' => 'Payments'] as $value => $label)
                    <button wire:click="$set('tab', '{{ $value }}')" class="px-3 py-1.5 text-sm rounded-md {{ $tab === $value ? 'bg-navy text-white' : 'bg-white text-brand-slate border border-brand-border' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'general')
                <form wire:submit="saveGeneral" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 max-w-xl space-y-5">
                    <div>
                        <x-input-label for="org_name" value="Organisation Name" />
                        <x-text-input id="org_name" type="text" wire:model="org_name" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('org_name')" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="org_contact_email" value="Contact Email" />
                            <x-text-input id="org_contact_email" type="email" wire:model="org_contact_email" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="org_contact_phone" value="Contact Phone" />
                            <x-text-input id="org_contact_phone" type="text" wire:model="org_contact_phone" class="mt-1 block w-full" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="currency" value="Currency" />
                            <x-text-input id="currency" type="text" wire:model="currency" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="date_format" value="Date Format (PHP format)" />
                            <x-text-input id="date_format" type="text" wire:model="date_format" class="mt-1 block w-full" />
                        </div>
                    </div>
                    <x-primary-button type="submit">Save General Settings</x-primary-button>
                </form>
            @elseif ($tab === 'finance')
                <div class="grid grid-cols-2 gap-6 max-w-3xl">
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6">
                        <h3 class="text-sm font-semibold text-charcoal mb-3">Expense Categories</h3>
                        <form wire:submit="addExpenseCategory" class="flex gap-2 mb-4">
                            <x-text-input type="text" wire:model="new_expense_category" placeholder="New category…" class="flex-1" />
                            <x-secondary-button type="submit">Add</x-secondary-button>
                        </form>
                        <x-input-error :messages="$errors->get('new_expense_category')" class="mb-2" />
                        <ul class="divide-y divide-gray-100">
                            @foreach ($expenseCategories as $category)
                                <li class="py-2 flex items-center justify-between text-sm">
                                    <span class="{{ $category->is_active ? 'text-charcoal' : 'text-brand-slate line-through' }}">{{ $category->name }}</span>
                                    <button wire:click="toggleExpenseCategory({{ $category->id }})" class="text-xs text-teal hover:underline">
                                        {{ $category->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6">
                        <h3 class="text-sm font-semibold text-charcoal mb-3">Income Categories</h3>
                        <form wire:submit="addIncomeCategory" class="flex gap-2 mb-4">
                            <x-text-input type="text" wire:model="new_income_category" placeholder="New category…" class="flex-1" />
                            <x-secondary-button type="submit">Add</x-secondary-button>
                        </form>
                        <x-input-error :messages="$errors->get('new_income_category')" class="mb-2" />
                        <ul class="divide-y divide-gray-100">
                            @foreach ($incomeCategories as $category)
                                <li class="py-2 flex items-center justify-between text-sm">
                                    <span class="{{ $category->is_active ? 'text-charcoal' : 'text-brand-slate line-through' }}">{{ $category->name }}</span>
                                    <button wire:click="toggleIncomeCategory({{ $category->id }})" class="text-xs text-teal hover:underline">
                                        {{ $category->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @elseif ($tab === 'property')
                <div class="grid grid-cols-3 gap-6 max-w-4xl">
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6">
                        <h3 class="text-sm font-semibold text-charcoal mb-3">Property Types</h3>
                        <ul class="text-sm text-brand-slate space-y-1">
                            @foreach (['Apartment', 'Residential Compound', 'Individual House', 'Maisonette', 'Commercial Building', 'Mixed Use', 'Other'] as $type)
                                <li>{{ $type }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6">
                        <h3 class="text-sm font-semibold text-charcoal mb-3">Unit Types</h3>
                        <ul class="text-sm text-brand-slate space-y-1">
                            @foreach (['Bedsitter', 'Studio', 'SQ', 'One Bedroom', 'Two Bedroom', 'Three Bedroom', 'Four Bedroom', 'House', 'Maisonette', 'Shop', 'Office', 'Other'] as $type)
                                <li>{{ $type }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6">
                        <h3 class="text-sm font-semibold text-charcoal mb-3">Unit Statuses</h3>
                        <ul class="text-sm text-brand-slate space-y-1">
                            @foreach (['Vacant', 'Occupied', 'Reserved', 'Pending Approval', 'Under Maintenance', 'Unavailable'] as $status)
                                <li>{{ $status }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <p class="text-xs text-brand-slate mt-4 max-w-2xl">
                    These lists are defined at the code level in this version of the system rather than editable here — changing them requires a small code change since they're used throughout filtering and reporting. Everything else on this page is fully editable.
                </p>
            @elseif ($tab === 'payments')
                <form wire:submit="savePayments" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 max-w-xl space-y-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-charcoal">Kats Paybill (M-Pesa)</h3>
                            <p class="text-xs text-brand-slate mt-1">The platform's own paybill — every tenant whose landlord hasn't set up direct collection pays into this.</p>
                        </div>
                        <x-status-badge :status="$platformConfigured ? 'active' : 'inactive'" />
                    </div>

                    <div>
                        <x-input-label for="mpesa_env" value="Environment" />
                        <select id="mpesa_env" wire:model="mpesa_env" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="sandbox">Sandbox (testing — never moves real money)</option>
                            <option value="production">Production (live)</option>
                        </select>
                        <x-input-error :messages="$errors->get('mpesa_env')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="mpesa_consumer_key" value="Consumer Key" />
                            <x-text-input id="mpesa_consumer_key" type="text" wire:model="mpesa_consumer_key" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('mpesa_consumer_key')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="mpesa_consumer_secret" value="Consumer Secret" />
                            <x-text-input id="mpesa_consumer_secret" type="password" wire:model="mpesa_consumer_secret" class="mt-1 block w-full" placeholder="Leave blank to keep existing value" />
                            <x-input-error :messages="$errors->get('mpesa_consumer_secret')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="mpesa_shortcode" value="Shortcode (Paybill/Till)" />
                            <x-text-input id="mpesa_shortcode" type="text" wire:model="mpesa_shortcode" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('mpesa_shortcode')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="mpesa_passkey" value="Daraja Passkey" />
                            <x-text-input id="mpesa_passkey" type="password" wire:model="mpesa_passkey" class="mt-1 block w-full" placeholder="Leave blank to keep existing value" />
                            <x-input-error :messages="$errors->get('mpesa_passkey')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="mpesa_callback_url" value="Callback URL (public HTTPS base — no secret suffix)" />
                        <x-text-input id="mpesa_callback_url" type="text" wire:model="mpesa_callback_url" class="mt-1 block w-full" placeholder="https://your-domain.com/mpesa/callback" />
                        <x-input-error :messages="$errors->get('mpesa_callback_url')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="mpesa_callback_secret" value="Callback Secret" />
                        <p class="text-xs text-brand-slate mb-1">
                            Appended to the callback URL automatically — Daraja callbacks carry no signature of their own, so this is what stops a forged "payment succeeded" request. Register the full URL below with Safaricom, not the base URL above.
                        </p>
                        <div class="flex gap-2">
                            <x-text-input id="mpesa_callback_secret" type="text" wire:model="mpesa_callback_secret" class="block w-full font-mono text-xs" />
                            <x-secondary-button type="button" wire:click="generateCallbackSecret">Generate New</x-secondary-button>
                        </div>
                        <x-input-error :messages="$errors->get('mpesa_callback_secret')" class="mt-1" />
                        @if ($mpesa_callback_url && $mpesa_callback_secret)
                            <p class="text-xs text-brand-slate mt-2 break-all">Full URL to register with Safaricom: <span class="font-mono">{{ rtrim($mpesa_callback_url, '/') }}/{{ $mpesa_callback_secret }}</span></p>
                        @endif
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Payment Settings</x-primary-button>
                        <x-secondary-button type="button" wire:click="testConnection">Test Connection</x-secondary-button>
                    </div>
                    <p class="text-xs text-brand-slate">Save your changes first, then Test Connection to confirm Safaricom accepts them.</p>
                </form>
            @endif
        </div>
    </div>
