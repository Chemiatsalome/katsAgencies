    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $manager ? 'Edit Manager' : 'Invite Manager' }}</h2>
                <a href="{{ route('manage.managers.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">
                    View all managers
                </a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

                <div class="flex items-center gap-2 mb-6 text-xs font-medium">
                    <span class="px-2 py-1 rounded-full {{ $step >= 1 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">1. Details</span>
                    <span class="text-brand-slate">—</span>
                    <span class="px-2 py-1 rounded-full {{ $step >= 2 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">2. Access &amp; Permissions</span>
                    @unless ($manager)
                        <span class="text-brand-slate">—</span>
                        <span class="px-2 py-1 rounded-full {{ $step >= 3 ? 'bg-navy text-white' : 'bg-gray-100 text-brand-slate' }}">3. Send Invitation</span>
                    @endunless
                </div>

                @if ($step === 1)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                        <div>
                            <x-input-label for="name" value="Full Name" />
                            <x-text-input id="name" type="text" wire:model="name" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="phone" value="Phone Number" />
                                <x-text-input id="phone" type="text" wire:model="phone" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="email" value="Email Address" />
                                <x-text-input id="email" type="email" wire:model="email" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('email')" class="mt-1" />
                                @if ($manager?->user_id)
                                    <p class="text-xs text-brand-slate mt-1">Changing this also updates their login email.</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <x-primary-button wire:click="goToStep2" type="button">Next: Access &amp; Permissions</x-primary-button>
                            <a href="{{ route('manage.managers.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                        </div>
                    </div>
                @elseif ($step === 2)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-6">
                        <div>
                            <x-input-label value="Property Access" />
                            <label class="flex items-center gap-2 text-sm text-brand-slate mt-2">
                                <input type="checkbox" wire:model.live="all_properties" class="rounded border-brand-border">
                                Grant access to all current and future properties
                            </label>

                            @unless ($all_properties)
                                <div class="mt-3 grid grid-cols-2 gap-2 border border-brand-border rounded-md p-3 max-h-48 overflow-y-auto">
                                    @forelse ($properties as $property)
                                        <label class="flex items-center gap-2 text-sm text-charcoal">
                                            <input type="checkbox" wire:model="property_ids" value="{{ $property->id }}" class="rounded border-brand-border">
                                            {{ $property->name }}
                                        </label>
                                    @empty
                                        <p class="text-sm text-gray-400 col-span-2">You have no properties yet.</p>
                                    @endforelse
                                </div>
                                <x-input-error :messages="$errors->get('property_ids')" class="mt-1" />
                            @endunless
                        </div>

                        <div class="pt-4 border-t border-brand-border space-y-4">
                            <x-input-label value="Permissions" />
                            <p class="text-xs text-brand-slate -mt-2">Everything is unchecked by default. Financial figures are hidden unless explicitly granted.</p>

                            @foreach ($permissionGroups as $group => $groupPermissions)
                                <div>
                                    <p class="text-xs font-semibold text-charcoal uppercase tracking-wide mb-1">{{ $group }}</p>
                                    <div class="grid grid-cols-2 gap-1.5">
                                        @foreach ($groupPermissions as $permission)
                                            <label class="flex items-center gap-2 text-sm text-brand-slate">
                                                <input type="checkbox" wire:model="permissions" value="{{ $permission }}" class="rounded border-brand-border">
                                                {{ $permissionLabels[$permission] ?? $permission }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <x-primary-button wire:click="save" type="button">{{ $manager ? 'Save Changes' : 'Send Invitation' }}</x-primary-button>
                            <button type="button" wire:click="backToStep1" class="text-sm text-brand-slate hover:text-charcoal">Back</button>
                        </div>
                    </div>
                @elseif ($step === 3 && $manager)
                    @php
                        $link = route('manager-invitations.accept', $manager->token);
                        $landlordName = auth()->user()->name;
                        $shareText = "You're invited to manage properties on behalf of {$landlordName} at Kats Agencies. Complete your registration: {$link}";
                    @endphp
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5" x-data="{ copied: false }">
                        <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">
                            Invitation created. Expires in 7 days.
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
                            <a href="mailto:{{ $manager->email }}?subject={{ urlencode('Manager Invitation — Kats Agencies') }}&body={{ urlencode($shareText) }}" class="px-3 py-2 rounded-md border border-brand-border hover:bg-gray-50">Send via Email</a>
                            <a href="sms:{{ $manager->phone }}?body={{ urlencode($shareText) }}" class="px-3 py-2 rounded-md border border-brand-border hover:bg-gray-50">Send via SMS</a>
                            <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', (string) $manager->phone) }}?text={{ urlencode($shareText) }}" target="_blank" rel="noopener" class="px-3 py-2 rounded-md border border-brand-border hover:bg-gray-50">Share via WhatsApp</a>
                        </div>

                        <div class="flex items-center gap-3 pt-3 border-t border-brand-border">
                            <a href="{{ route('manage.managers.index') }}" wire:navigate class="text-sm text-teal hover:underline">View all managers</a>
                            <a href="{{ route('manage.managers.create') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Invite another manager</a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
