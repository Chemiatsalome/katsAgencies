    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-brand-slate">
                        <a href="{{ route('manage.applications.index') }}" wire:navigate class="hover:underline">Applications</a>
                    </p>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $application->applicant_name }}</h2>
                </div>
                <x-status-badge :status="$application->status" />
            </div>
        </x-slot>

        <div class="p-6 space-y-6 max-w-2xl">
            <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-brand-slate">Phone</p>
                    <p class="text-charcoal font-medium">{{ $application->applicant_phone }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Email</p>
                    <p class="text-charcoal font-medium">{{ $application->applicant_email ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Desired Property</p>
                    <p class="text-charcoal font-medium">{{ $application->property->name }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Desired Unit</p>
                    <p class="text-charcoal font-medium">{{ $application->house?->house_number ?? 'Any' }}</p>
                </div>
                <div>
                    <p class="text-brand-slate">Application Date</p>
                    <p class="text-charcoal font-medium">{{ $application->application_date->format('d M Y') }}</p>
                </div>
                <div class="col-span-2">
                    <p class="text-brand-slate">Notes</p>
                    <p class="text-charcoal">{{ $application->notes ?: '—' }}</p>
                </div>
            </div>

            @can('update', $application)
                @if (! in_array($application->status, ['approved', 'rejected', 'withdrawn']))
                    <div class="flex flex-wrap gap-2">
                        <button wire:click="updateStatus('under_review')" class="px-3 py-1.5 text-sm rounded-md border border-brand-border text-charcoal hover:bg-offwhite">Mark Under Review</button>
                        <button wire:click="updateStatus('more_info_required')" class="px-3 py-1.5 text-sm rounded-md border border-brand-border text-charcoal hover:bg-offwhite">Request More Info</button>
                        <button wire:click="$toggle('showApproveForm')" class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">Approve &amp; Convert to Tenancy</button>
                        <button wire:click="updateStatus('rejected')" wire:confirm="Reject this application?" class="px-3 py-1.5 text-sm rounded-md border border-red-200 text-danger">Reject</button>
                    </div>

                    @if ($showApproveForm)
                        <form wire:submit="approveAndConvert" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                            <h3 class="text-sm font-semibold text-charcoal">Move-in Details</h3>
                            <div>
                                <x-input-label for="house_id" value="Unit" />
                                <select id="house_id" wire:model.live="house_id" class="mt-1 block w-full rounded-md border-brand-border">
                                    <option value="">Select a vacant unit…</option>
                                    @foreach (\App\Models\House::vacant()->whereHas('block', fn($q) => $q->where('property_id', $application->desired_property_id))->get() as $house)
                                        <option value="{{ $house->id }}">{{ $house->house_number }} — KSh {{ number_format($house->monthly_rent) }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('house_id')" class="mt-1" />
                            </div>
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <x-input-label for="move_in_date" value="Move-in Date" />
                                    <x-text-input id="move_in_date" type="date" wire:model="move_in_date" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="monthly_rent" value="Monthly Rent" />
                                    <x-text-input id="monthly_rent" type="number" step="0.01" wire:model="monthly_rent" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="deposit_required" value="Deposit Required" />
                                    <x-text-input id="deposit_required" type="number" step="0.01" wire:model="deposit_required" class="mt-1 block w-full" />
                                </div>
                            </div>
                            <x-primary-button type="submit">Confirm &amp; Create Tenancy</x-primary-button>
                        </form>
                    @endif
                @endif
            @endcan

            @if ($application->converted_tenancy_id)
                <a href="{{ route('manage.tenancies.show', $application->converted_tenancy_id) }}" wire:navigate class="text-sm text-teal hover:underline">
                    View resulting tenancy →
                </a>
            @endif
        </div>
    </div>
