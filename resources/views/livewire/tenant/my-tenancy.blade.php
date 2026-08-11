    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Tenancy</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                @if (! $tenancy)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        No active tenancy is linked to your account yet.
                    </div>
                @else
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-charcoal">Current Tenancy</h3>
                            <x-status-badge :status="$tenancy->status" />
                        </div>

                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div><p class="text-brand-slate">Property</p><p class="font-medium text-charcoal">{{ $tenancy->house->block->property->name }}</p></div>
                            <div><p class="text-brand-slate">Block</p><p class="font-medium text-charcoal">{{ $tenancy->house->block->name }}</p></div>
                            <div><p class="text-brand-slate">Unit</p><p class="font-medium text-charcoal">{{ $tenancy->house->house_number }}</p></div>
                            <div><p class="text-brand-slate">Monthly Rent</p><p class="font-medium text-charcoal">KSh {{ number_format($tenancy->monthly_rent, 2) }}</p></div>
                            <div><p class="text-brand-slate">Deposit</p><p class="font-medium text-charcoal">KSh {{ number_format($tenancy->deposit_required, 2) }}</p></div>
                            <div><p class="text-brand-slate">Rent Due Date</p><p class="font-medium text-charcoal">{{ $tenancy->dueDay() }}{{ match(true) { in_array($tenancy->dueDay(), [1,21,31]) => 'st', in_array($tenancy->dueDay(), [2,22]) => 'nd', in_array($tenancy->dueDay(), [3,23]) => 'rd', default => 'th' } }}</p></div>
                            <div><p class="text-brand-slate">Start Date</p><p class="font-medium text-charcoal">{{ $tenancy->move_in_date->format('d M Y') }}</p></div>
                            <div><p class="text-brand-slate">End Date</p><p class="font-medium text-charcoal">{{ $tenancy->lease_end_date?->format('d M Y') ?? 'Open-ended' }}</p></div>
                            @if ($tenancy->notice_period_days)
                                <div><p class="text-brand-slate">Notice Period</p><p class="font-medium text-charcoal">{{ $tenancy->notice_period_days }} days</p></div>
                            @endif
                        </div>

                        <div class="pt-3 border-t border-brand-border flex flex-wrap gap-3">
                            <button wire:click="$toggle('showRequestChangeForm')" class="text-sm text-teal hover:underline">
                                {{ $showRequestChangeForm ? 'Cancel' : 'Request Change' }}
                            </button>
                            <button wire:click="$toggle('showRenewalForm')" class="text-sm text-teal hover:underline">
                                {{ $showRenewalForm ? 'Cancel' : 'Contact Landlord About Renewal' }}
                            </button>
                        </div>

                        @if ($showRequestChangeForm)
                            <form wire:submit="requestChange" class="space-y-2 pt-2">
                                <x-input-label for="requestMessage" value="What would you like corrected or changed?" />
                                <textarea id="requestMessage" wire:model="requestMessage" rows="3" class="block w-full rounded-md border-brand-border text-sm"></textarea>
                                <x-input-error :messages="$errors->get('requestMessage')" class="mt-1" />
                                <x-primary-button type="submit">Send Request</x-primary-button>
                            </form>
                        @endif

                        @if ($showRenewalForm)
                            <form wire:submit="requestRenewal" class="space-y-2 pt-2">
                                <x-input-label for="renewalMessage" value="Message to your landlord (optional)" />
                                <textarea id="renewalMessage" wire:model="renewalMessage" rows="3" class="block w-full rounded-md border-brand-border text-sm"></textarea>
                                <x-primary-button type="submit">Send</x-primary-button>
                            </form>
                        @endif
                    </div>
                @endif

                @if ($pastTenancies->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                        <div class="px-5 py-4 border-b border-brand-border">
                            <h3 class="text-sm font-semibold text-brand-slate">Previous Tenancies</h3>
                        </div>
                        <div class="divide-y divide-gray-100">
                            @foreach ($pastTenancies as $past)
                                <div class="px-5 py-3 text-sm flex items-center justify-between">
                                    <div>
                                        <p class="text-charcoal font-medium">{{ $past->house->block->property->name }} — {{ $past->house->house_number }}</p>
                                        <p class="text-brand-slate text-xs">{{ $past->move_in_date->format('M Y') }} – {{ $past->move_out_date?->format('M Y') ?? ($past->lease_end_date?->format('M Y') ?? 'present') }}</p>
                                    </div>
                                    <x-status-badge :status="$past->status" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
