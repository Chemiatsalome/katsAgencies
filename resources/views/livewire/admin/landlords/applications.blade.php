    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Landlord Applications</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif

            <p class="text-sm text-brand-slate max-w-2xl">
                People who registered wanting to list properties on Kats Agencies. Approve to activate their account, or reject to remove the application — rejecting emails them the reason, so they know what to fix before reapplying.
            </p>

            <div class="space-y-4">
                @forelse ($pending as $applicant)
                    @php $duplicateSignal = $duplicateSignalFor[$applicant->id] ?? null; @endphp
                    <div wire:key="applicant-{{ $applicant->id }}" class="bg-white shadow-sm rounded-lg border {{ $duplicateSignal ? 'border-warning/40' : 'border-brand-border' }} p-5">
                        @if ($duplicateSignal)
                            <div class="mb-3 rounded-md bg-warning/10 border border-warning/30 text-xs text-charcoal px-3 py-2 flex items-start gap-1.5">
                                <x-nav-icon name="warning" class="h-3.5 w-3.5 shrink-0 mt-0.5 text-warning" />
                                <span>{{ $duplicateSignal }} Worth checking it's not a repeat or duplicate application before deciding.</span>
                            </div>
                        @endif
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-medium text-charcoal">{{ $applicant->name }}</p>
                                <p class="text-sm text-brand-slate">{{ $applicant->email }}</p>
                                <p class="text-xs text-gray-400 mt-1">Applied {{ $applicant->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <button wire:click="approve({{ $applicant->id }})" wire:confirm="Approve {{ $applicant->name }} as a landlord?" class="text-success hover:underline text-sm font-medium">Approve</button>
                                @if ($rejectingApplicantId !== $applicant->id)
                                    <button wire:click="openRejectForm({{ $applicant->id }})" class="text-danger hover:underline text-sm font-medium">Reject</button>
                                @endif
                            </div>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                            <div>
                                <dt class="text-xs text-gray-400">Phone</dt>
                                <dd class="text-charcoal">{{ $applicant->phone ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-400">National ID</dt>
                                <dd class="text-charcoal">{{ $applicant->national_id ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-400">Business / agency</dt>
                                <dd class="text-charcoal">{{ $applicant->business_name ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-400">Intended units</dt>
                                <dd class="text-charcoal">{{ $applicant->intended_unit_count ?? '—' }}</dd>
                            </div>
                            <div class="col-span-2 sm:col-span-4">
                                <dt class="text-xs text-gray-400">Property location</dt>
                                <dd class="text-charcoal">{{ $applicant->property_location ?: '—' }}</dd>
                            </div>
                        </dl>

                        @if ($rejectingApplicantId === $applicant->id)
                            <div class="mt-4 pt-4 border-t border-brand-border space-y-3">
                                <div>
                                    <x-input-label for="rejectionPreset" value="Reason" />
                                    <select id="rejectionPreset" wire:model.live="rejectionPreset" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                        <option value="">Choose a reason…</option>
                                        @foreach (\App\Livewire\Admin\Landlords\Applications::REASON_PRESETS as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <textarea wire:model="rejectionReason" rows="3" class="block w-full rounded-md border-brand-border text-sm" placeholder="This is emailed to the applicant, so write it as you'd want them to read it."></textarea>
                                    <x-input-error :messages="$errors->get('rejectionReason')" class="mt-1" />
                                </div>
                                <div class="flex items-center gap-3">
                                    <button wire:click="reject" wire:confirm="Reject {{ $applicant->name }}'s application and email them this reason?" class="text-sm text-white bg-danger rounded-md px-3 py-1.5 hover:opacity-90">Confirm Reject</button>
                                    <button type="button" wire:click="cancelRejectForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-center text-gray-400">
                        No pending landlord applications.
                    </div>
                @endforelse
            </div>

            <div>{{ $pending->links() }}</div>
        </div>
    </div>
