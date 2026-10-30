    <div x-data x-on:property-created.window="window.location.replace($event.detail.url)">
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Add Property</h2>
                <a href="{{ route('manage.properties.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                @if ($step === 1)
                    <x-page-banner
                        image="modern-villa-01.jpg"
                        eyebrow="Add Property"
                        title="Let's get your property set up"
                        subtitle="A few steps to describe the property, its units, and rent — then you're ready to start managing it."
                    />
                @endif

                <div class="flex items-center gap-2 text-xs font-medium flex-wrap">
                    <span class="px-2 py-1 rounded-full {{ $step >= 1 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">1. Property</span>
                    <span class="text-brand-slate">—</span>
                    <span class="px-2 py-1 rounded-full {{ $step >= 2 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">2. Rentable Spaces</span>
                    <span class="text-brand-slate">—</span>
                    <span class="px-2 py-1 rounded-full {{ $step >= 3 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">3. Rent &amp; Deposit</span>
                    <span class="text-brand-slate">—</span>
                    <span class="px-2 py-1 rounded-full {{ $step >= 4 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">4. Review</span>
                </div>

                {{-- ============================= STEP 1 ============================= --}}
                @if ($step === 1)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                        <p class="text-sm text-brand-slate">Tell us about the property. You'll describe the actual units in the next step.</p>

                        @if ($landlords->isNotEmpty())
                            <div>
                                <x-input-label for="landlord_id" value="Landlord" />
                                <select id="landlord_id" wire:model="landlord_id" class="mt-1 block w-full rounded-md border-brand-border">
                                    <option value="">Select landlord…</option>
                                    @foreach ($landlords as $landlord)
                                        <option value="{{ $landlord->id }}">{{ $landlord->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('landlord_id')" class="mt-1" />
                            </div>
                        @endif

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="type" value="What kind of property is this?" />
                                <select id="type" wire:model.live="type" class="mt-1 block w-full rounded-md border-brand-border">
                                    @foreach ($propertyTypes as $value)
                                        <option value="{{ $value }}">{{ \Illuminate\Support\Str::headline($value) }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('type')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="name" value="Property Name" />
                                <x-text-input id="name" type="text" wire:model="name" class="mt-1 block w-full" placeholder="e.g. Greenview Villas" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <x-location-autocomplete
                                id="address"
                                label="Address / Location (optional)"
                                address-property="address"
                                lat-property="latitude"
                                lon-property="longitude"
                                :initial-address="$address"
                            />
                            <p class="text-xs text-brand-slate mt-1">Pick a suggestion as you type, or just type the address manually — either way is saved.</p>
                            <x-input-error :messages="$errors->get('address')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="photo" value="Photo (optional)" />
                            <input type="file" id="photo" wire:model="photo" accept="image/*" class="mt-1 block w-full text-sm" />
                            <div wire:loading wire:target="photo" class="text-xs text-brand-slate mt-1">Uploading…</div>
                            @if ($photo)
                                <img src="{{ $photo->temporaryUrl() }}" class="mt-2 h-24 w-24 object-cover rounded-md border border-brand-border" alt="Preview">
                            @endif
                            <p class="text-xs text-brand-slate mt-1">You can always add or change this later — it's not required to list the property.</p>
                            <x-input-error :messages="$errors->get('photo')" class="mt-1" />
                        </div>

                        <p class="text-xs text-brand-slate">Purchase price, valuation and other investment details can be added later from the property's detailed edit screen — they're not needed to list it.</p>

                        <div class="flex items-center gap-3">
                            <x-primary-button type="button" wire:click="goToStep2">Next: Rentable Spaces</x-primary-button>
                        </div>
                    </div>
                @endif

                {{-- ============================= STEP 2 ============================= --}}
                @if ($step === 2)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                        <div>
                            <x-input-label value="How are the rentable spaces organized?" />
                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label class="flex items-start gap-2 border rounded-md p-3 cursor-pointer {{ $layout === 'standalone' ? 'border-teal bg-teal/5' : 'border-brand-border' }}">
                                    <input type="radio" wire:model.live="layout" value="standalone" class="mt-1">
                                    <span>
                                        <span class="block text-sm font-medium text-charcoal">One standalone property</span>
                                        <span class="block text-xs text-brand-slate">A single house, maisonette or unit — no blocks needed.</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2 border rounded-md p-3 cursor-pointer {{ $layout === 'multiple_similar' ? 'border-teal bg-teal/5' : 'border-brand-border' }}">
                                    <input type="radio" wire:model.live="layout" value="multiple_similar" class="mt-1">
                                    <span>
                                        <span class="block text-sm font-medium text-charcoal">Multiple similar houses or villas</span>
                                        <span class="block text-xs text-brand-slate">Several units of the same or different types, no real blocks.</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2 border rounded-md p-3 cursor-pointer {{ $layout === 'blocks' ? 'border-teal bg-teal/5' : 'border-brand-border' }}">
                                    <input type="radio" wire:model.live="layout" value="blocks" class="mt-1">
                                    <span>
                                        <span class="block text-sm font-medium text-charcoal">An apartment building with blocks</span>
                                        <span class="block text-xs text-brand-slate">Units organized into separate blocks (e.g. Block A, Block B).</span>
                                    </span>
                                </label>
                            </div>
                            <x-input-error :messages="$errors->get('layout')" class="mt-1" />
                        </div>

                        @if ($layout === 'blocks')
                            <div>
                                <x-input-label for="block_count" value="Number of blocks" />
                                <input type="number" id="block_count" wire:model.live="block_count" min="1" max="50" class="mt-1 block w-32 rounded-md border-brand-border">
                                <p class="text-xs text-brand-slate mt-1">Blocks are named automatically ({{ implode(', ', array_slice($blockLabels, 0, 3)) }}{{ count($blockLabels) > 3 ? ', …' : '' }}) — you don't need to name them.</p>
                                <x-input-error :messages="$errors->get('block_count')" class="mt-1" />
                            </div>
                        @endif

                        <div class="space-y-4">
                            @foreach ($groups as $i => $group)
                                <div class="border border-brand-border rounded-md p-4 space-y-3" wire:key="group-{{ $i }}">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-charcoal">
                                            Unit group {{ $i + 1 }}
                                            @if ($layout === 'blocks')
                                                — {{ $blockLabels[$group['block_index']] ?? 'Block' }}
                                            @endif
                                        </p>
                                        @if (count($groups) > 1)
                                            <button type="button" wire:click="removeGroup({{ $i }})" class="text-xs text-danger hover:underline">Remove</button>
                                        @endif
                                    </div>

                                    @if ($layout === 'blocks')
                                        <div>
                                            <x-input-label value="Which block?" />
                                            <select wire:model="groups.{{ $i }}.block_index" class="mt-1 block w-full rounded-md border-brand-border">
                                                @foreach ($blockLabels as $index => $label)
                                                    <option value="{{ $index }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif

                                    @php $groupImpliedBedrooms = \App\Models\House::bedroomsImpliedByType($group['unit_type']); @endphp
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <x-input-label value="Unit type" />
                                            <select wire:model.live="groups.{{ $i }}.unit_type" class="mt-1 block w-full rounded-md border-brand-border">
                                                @foreach ($houseTypes as $value)
                                                    <option value="{{ $value }}">{{ \Illuminate\Support\Str::headline($value) }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('groups.'.$i.'.unit_type')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label value="Bedrooms (optional)" />
                                            <input type="number" wire:model="groups.{{ $i }}.bedrooms" min="0" max="20" class="mt-1 block w-full rounded-md border-brand-border" @disabled($groupImpliedBedrooms !== null)>
                                            @if ($groupImpliedBedrooms !== null)
                                                <p class="text-xs text-brand-slate mt-1">Set automatically from the unit type.</p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <x-input-label value="Number of units" />
                                            <input type="number" wire:model.live="groups.{{ $i }}.count" min="1" max="50" class="mt-1 block w-full rounded-md border-brand-border" @disabled($layout === 'standalone')>
                                            <x-input-error :messages="$errors->get('groups.'.$i.'.count')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label value="Starting unit number" />
                                            <x-text-input type="text" wire:model="groups.{{ $i }}.starting_number" class="mt-1 block w-full" />
                                            <p class="text-xs text-brand-slate mt-1">Editable in the review step too.</p>
                                        </div>
                                    </div>

                                    <div>
                                        <x-input-label value="Monthly rent per unit (KSh)" />
                                        <input type="number" wire:model="groups.{{ $i }}.monthly_rent" min="0" step="0.01" class="mt-1 block w-full rounded-md border-brand-border">
                                        <x-input-error :messages="$errors->get('groups.'.$i.'.monthly_rent')" class="mt-1" />
                                    </div>

                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <x-input-label value="Electricity" />
                                            <select wire:model="groups.{{ $i }}.electricity_arrangement" class="mt-1 block w-full rounded-md border-brand-border">
                                                <option value="separate_meter">Separate meter</option>
                                                <option value="shared">Shared</option>
                                                <option value="included">Included in rent</option>
                                            </select>
                                        </div>
                                        <div>
                                            <x-input-label value="Water" />
                                            <select wire:model="groups.{{ $i }}.water_arrangement" class="mt-1 block w-full rounded-md border-brand-border">
                                                <option value="shared">Shared</option>
                                                <option value="separate_meter">Separate meter</option>
                                                <option value="included">Included in rent</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if ($layout !== 'standalone')
                            <button type="button" wire:click="addGroup" class="text-sm text-teal hover:underline font-medium">+ Add another unit type or rent category</button>
                        @endif

                        <div class="flex items-center gap-3 pt-2">
                            <x-secondary-button type="button" wire:click="backToStep(1)">Back</x-secondary-button>
                            <x-primary-button type="button" wire:click="goToStep3">Next: Rent &amp; Deposit</x-primary-button>
                        </div>
                    </div>
                @endif

                {{-- ============================= STEP 3 ============================= --}}
                @if ($step === 3)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                        <p class="text-sm text-brand-slate">Set the deposit arrangement for each unit group. This only records what's <strong>required</strong> — nothing is marked as received until an actual payment is recorded later, when a tenant moves in.</p>

                        <div class="space-y-4">
                            @foreach ($groups as $i => $group)
                                <div class="border border-brand-border rounded-md p-4 space-y-3" wire:key="deposit-group-{{ $i }}">
                                    <p class="text-sm font-medium text-charcoal">
                                        Unit group {{ $i + 1 }} — {{ \Illuminate\Support\Str::headline($group['unit_type']) }}, KSh {{ number_format((float) ($group['monthly_rent'] ?: 0), 2) }}/month
                                    </p>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                        @foreach (['none' => 'No deposit', 'months' => "Months' rent", 'custom' => 'Custom amount'] as $mode => $label)
                                            <label class="flex items-center gap-2 border rounded-md p-2 text-xs cursor-pointer {{ $group['deposit_mode'] === $mode ? 'border-teal bg-teal/5' : 'border-brand-border' }}">
                                                <input type="radio" wire:model.live="groups.{{ $i }}.deposit_mode" value="{{ $mode }}">
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                    <x-input-error :messages="$errors->get('groups.'.$i.'.deposit_mode')" class="mt-1" />

                                    @if ($group['deposit_mode'] === 'months')
                                        <div>
                                            <x-input-label value="Deposit, in months of rent" />
                                            <select wire:model.live="groups.{{ $i }}.deposit_months" class="mt-1 block w-full max-w-xs rounded-md border-brand-border">
                                                @for ($m = 1; $m <= 12; $m++)
                                                    <option value="{{ $m }}">{{ $m }} {{ Str::plural('month', $m) }}{{ $m === 1 ? " (one month's rent)" : '' }}</option>
                                                @endfor
                                            </select>
                                            <p class="text-xs text-brand-slate mt-1">
                                                Deposit will be set to KSh {{ number_format((float) ($group['monthly_rent'] ?: 0) * (int) $group['deposit_months']) }} per unit
                                                ({{ $group['deposit_months'] }} &times; KSh {{ number_format((float) ($group['monthly_rent'] ?: 0)) }} monthly rent).
                                            </p>
                                            <x-input-error :messages="$errors->get('groups.'.$i.'.deposit_months')" class="mt-1" />
                                        </div>
                                    @elseif ($group['deposit_mode'] === 'custom')
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <x-input-label value="Deposit amount (KSh)" />
                                                <input type="number" wire:model="groups.{{ $i }}.deposit_custom_amount" min="0" step="0.01" class="mt-1 block w-full rounded-md border-brand-border">
                                                <x-input-error :messages="$errors->get('groups.'.$i.'.deposit_custom_amount')" class="mt-1" />
                                            </div>
                                            <div>
                                                <x-input-label value="Note (optional)" />
                                                <x-text-input type="text" wire:model="groups.{{ $i }}.deposit_note" class="mt-1 block w-full" placeholder="e.g. balance due within 30 days" />
                                            </div>
                                        </div>
                                    @else
                                        <p class="text-xs text-brand-slate">No deposit will be required for this group.</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <x-secondary-button type="button" wire:click="backToStep(2)">Back</x-secondary-button>
                            <x-primary-button type="button" wire:click="goToStep4">Next: Review</x-primary-button>
                        </div>
                    </div>
                @endif

                {{-- ============================= STEP 4 ============================= --}}
                @if ($step === 4)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                        <div>
                            <h3 class="text-sm font-semibold text-charcoal">Review before saving</h3>
                            <p class="text-xs text-brand-slate mt-1">
                                {{ $name }} — {{ \Illuminate\Support\Str::headline($type) }} — {{ count($reviewUnits) }} rentable {{ Str::plural('space', count($reviewUnits)) }}, all vacant by default.
                            </p>
                        </div>

                        <x-input-error :messages="$errors->get('reviewUnits')" class="mt-1" />

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-brand-slate">
                                    <tr>
                                        @if ($layout === 'blocks')
                                            <th class="text-left px-3 py-2 font-medium">Block</th>
                                        @endif
                                        <th class="text-left px-3 py-2 font-medium">Unit #</th>
                                        <th class="text-left px-3 py-2 font-medium">Type</th>
                                        <th class="text-right px-3 py-2 font-medium">Rent (KSh)</th>
                                        <th class="text-right px-3 py-2 font-medium">Deposit (KSh)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($reviewUnits as $i => $unit)
                                        <tr wire:key="review-{{ $i }}">
                                            @if ($layout === 'blocks')
                                                <td class="px-3 py-2 text-brand-slate">{{ $unit['block_label'] }}</td>
                                            @endif
                                            <td class="px-3 py-1"><x-text-input type="text" wire:model="reviewUnits.{{ $i }}.house_number" class="w-24" /></td>
                                            <td class="px-3 py-1">
                                                <select wire:model="reviewUnits.{{ $i }}.type" class="rounded-md border-brand-border text-sm">
                                                    @foreach ($houseTypes as $value)
                                                        <option value="{{ $value }}">{{ \Illuminate\Support\Str::headline($value) }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="px-3 py-1"><input type="number" step="0.01" wire:model="reviewUnits.{{ $i }}.monthly_rent" class="w-28 rounded-md border-brand-border text-sm text-right"></td>
                                            <td class="px-3 py-1"><input type="number" step="0.01" wire:model="reviewUnits.{{ $i }}.deposit_amount" class="w-28 rounded-md border-brand-border text-sm text-right"></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <x-secondary-button type="button" wire:click="backToStep(3)">Back to edit</x-secondary-button>
                            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">Save Property</span>
                                <span wire:loading wire:target="save">Saving…</span>
                            </x-primary-button>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    </div>
