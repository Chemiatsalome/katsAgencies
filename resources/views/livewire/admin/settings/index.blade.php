    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">System Settings</h2>
        </x-slot>

        <div class="p-6">
            @if (session('status'))
                <div class="mb-4 bg-success/10 border border-success/30 text-charcoal text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
            @endif

            <div class="flex gap-2 mb-6 flex-wrap">
                @foreach (['general' => 'General', 'rent' => 'Rent', 'notifications' => 'Notifications', 'finance' => 'Finance', 'property' => 'Property'] as $value => $label)
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
            @elseif ($tab === 'rent')
                <form wire:submit="saveRent" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 max-w-xl space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="default_due_day" value="Default Rent Due Day" />
                            <x-text-input id="default_due_day" type="number" min="1" max="31" wire:model="default_due_day" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="grace_period_days" value="Grace Period (days)" />
                            <x-text-input id="grace_period_days" type="number" min="0" wire:model="grace_period_days" class="mt-1 block w-full" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="arrears_threshold" value="Large Arrears Threshold (KSh)" />
                        <x-text-input id="arrears_threshold" type="number" step="0.01" wire:model="arrears_threshold" class="mt-1 block w-full" />
                        <p class="text-xs text-brand-slate mt-1">Tenancies with arrears above this amount are flagged as high-priority on the dashboard.</p>
                    </div>
                    <div>
                        <x-input-label for="late_payment_rule" value="Late Payment Rule (notes)" />
                        <textarea id="late_payment_rule" wire:model="late_payment_rule" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                    </div>
                    <x-primary-button type="submit">Save Rent Settings</x-primary-button>
                </form>
            @elseif ($tab === 'notifications')
                <form wire:submit="saveNotifications" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 max-w-xl space-y-5">
                    <div>
                        <x-input-label for="reminder_days_before" value="Send Rent Reminder (days before due)" />
                        <x-text-input id="reminder_days_before" type="number" min="0" wire:model="reminder_days_before" class="mt-1 block w-32" />
                    </div>
                    <div>
                        <x-input-label for="email_from_name" value="Email 'From' Name" />
                        <x-text-input id="email_from_name" type="text" wire:model="email_from_name" class="mt-1 block w-full" />
                    </div>
                    <div class="bg-navy-light text-navy text-sm rounded-md px-4 py-3">
                        SMS provider is not configured in this environment — SMS notifications are logged in the Notification Centre with a Pending status rather than delivered to a carrier.
                    </div>
                    <x-primary-button type="submit">Save Notification Settings</x-primary-button>
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
            @endif
        </div>
    </div>
