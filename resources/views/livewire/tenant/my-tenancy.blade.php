    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Tenancy</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="bg-red-50 border border-red-200 text-danger text-sm rounded-md px-4 py-2">{{ session('error') }}</div>
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
                            <div><p class="text-brand-slate">Required Security Deposit</p><p class="font-medium text-charcoal">KSh {{ number_format($tenancy->deposit_required, 2) }}</p></div>
                            <div><p class="text-brand-slate">Rent Due Date</p><p class="font-medium text-charcoal">{{ $tenancy->dueDay() }}{{ match(true) { in_array($tenancy->dueDay(), [1,21,31]) => 'st', in_array($tenancy->dueDay(), [2,22]) => 'nd', in_array($tenancy->dueDay(), [3,23]) => 'rd', default => 'th' } }}</p></div>
                            <div><p class="text-brand-slate">Start Date</p><p class="font-medium text-charcoal">{{ $tenancy->move_in_date->format('d M Y') }}</p></div>
                            <div><p class="text-brand-slate">End Date</p><p class="font-medium text-charcoal">{{ $tenancy->lease_end_date?->format('d M Y') ?? 'Open-ended' }}</p></div>
                            @if ($tenancy->notice_period_days)
                                <div><p class="text-brand-slate">Notice Period</p><p class="font-medium text-charcoal">{{ $tenancy->notice_period_days }} days</p></div>
                            @endif
                            <div>
                                <p class="text-brand-slate">Agreement Status</p>
                                <p class="font-medium text-charcoal">
                                    {{ $tenancy->contract?->effectiveStatus() ? ucfirst(str_replace('_', ' ', $tenancy->contract->effectiveStatus())) : 'No agreement on file' }}
                                    @if ($canRenew)
                                        <span class="block text-xs text-warning font-normal mt-0.5">Renewal review is now due</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-brand-border flex flex-wrap gap-3">
                            <button wire:click="openRequestChangeForm" class="text-sm text-teal hover:underline">Request a Change</button>
                            @if ($canRenew)
                                <button wire:click="openRenewalForm" class="text-sm text-teal hover:underline">Renew My Tenancy</button>
                            @endif
                        </div>

                        @if ($showRequestChangeForm)
                            <form wire:submit="submitChangeRequest" class="space-y-3 pt-2 border-t border-brand-border">
                                <div>
                                    <x-input-label for="change_category" value="What would you like to change?" />
                                    <select id="change_category" wire:model="change_category" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                        <option value="monthly_rent">Monthly rent</option>
                                        <option value="rent_due_date">Rent due date</option>
                                        <option value="security_deposit">Security deposit details</option>
                                        <option value="tenancy_agreement">Tenancy agreement details</option>
                                        <option value="property_unit_details">Property or unit details</option>
                                        <option value="other">Other</option>
                                    </select>
                                    <x-input-error :messages="$errors->get('change_category')" class="mt-1" />
                                </div>

                                @php
                                    $currentValueLabel = match ($change_category) {
                                        'monthly_rent' => 'KSh '.number_format($tenancy->monthly_rent, 2),
                                        'rent_due_date' => $tenancy->dueDay().' of each month',
                                        'security_deposit' => 'KSh '.number_format($tenancy->deposit_required, 2),
                                        'tenancy_agreement' => $tenancy->lease_end_date?->format('d M Y') ?? 'Open-ended',
                                        default => null,
                                    };
                                @endphp
                                @if ($currentValueLabel)
                                    <p class="text-xs text-brand-slate">Current value: <span class="font-medium text-charcoal">{{ $currentValueLabel }}</span></p>
                                @endif

                                <div>
                                    <x-input-label for="change_requested_change" value="Requested Change" />
                                    <textarea id="change_requested_change" wire:model="change_requested_change" rows="2" class="mt-1 block w-full rounded-md border-brand-border text-sm" placeholder="e.g. Change my rent due date to the 1st"></textarea>
                                    <x-input-error :messages="$errors->get('change_requested_change')" class="mt-1" />
                                </div>

                                <div>
                                    <x-input-label for="change_reason" value="Reason (optional)" />
                                    <textarea id="change_reason" wire:model="change_reason" rows="2" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                                    <x-input-error :messages="$errors->get('change_reason')" class="mt-1" />
                                </div>

                                <div>
                                    <x-input-label for="change_evidence" value="Supporting Document (optional)" />
                                    <input type="file" id="change_evidence" wire:model="change_evidence" class="mt-1 block w-full text-sm">
                                    <x-input-error :messages="$errors->get('change_evidence')" class="mt-1" />
                                </div>

                                <div class="flex gap-3">
                                    <x-primary-button type="submit">Submit Request</x-primary-button>
                                    <button type="button" wire:click="cancelRequestChangeForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                </div>
                            </form>
                        @endif

                        @if ($showRenewalForm)
                            <form wire:submit="submitRenewalRequest" class="space-y-3 pt-2 border-t border-brand-border">
                                <div>
                                    <x-input-label for="renewal_duration_months" value="Requested Renewal Duration" />
                                    <select id="renewal_duration_months" wire:model="renewal_duration_months" class="mt-1 block w-full max-w-xs rounded-md border-brand-border text-sm">
                                        <option value="12">12 months</option>
                                        <option value="24">24 months</option>
                                    </select>
                                    <x-input-error :messages="$errors->get('renewal_duration_months')" class="mt-1" />
                                </div>

                                <div>
                                    <x-input-label for="renewal_preferred_end_date" value="Preferred New End Date (optional)" />
                                    <x-text-input id="renewal_preferred_end_date" type="date" wire:model="renewal_preferred_end_date" class="mt-1 block w-full max-w-xs" />
                                    <x-input-error :messages="$errors->get('renewal_preferred_end_date')" class="mt-1" />
                                </div>

                                <div>
                                    <x-input-label for="renewal_message" value="Message to Your Landlord (optional)" />
                                    <textarea id="renewal_message" wire:model="renewal_message" rows="3" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                                    <x-input-error :messages="$errors->get('renewal_message')" class="mt-1" />
                                </div>

                                <div class="flex gap-3">
                                    <x-primary-button type="submit">Submit Renewal Request</x-primary-button>
                                    <button type="button" wire:click="cancelRenewalForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endif

                @if ($activeRenewalRequest || $renewalHistory->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-charcoal">Tenancy Renewal</h3>

                        @if ($activeRenewalRequest)
                            <div class="border border-brand-border rounded-md p-4 space-y-2">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm text-charcoal">Requested {{ $activeRenewalRequest->requested_duration_months }} month(s){{ $activeRenewalRequest->preferred_new_end_date ? ' — preferred end date '.$activeRenewalRequest->preferred_new_end_date->format('d M Y') : '' }}</p>
                                    <x-status-badge :status="$activeRenewalRequest->status" />
                                </div>
                                @if ($activeRenewalRequest->message)
                                    <p class="text-sm text-brand-slate">{{ $activeRenewalRequest->message }}</p>
                                @endif

                                @if ($activeRenewalRequest->status === 'alternative_proposed')
                                    <div class="bg-warning/5 border border-warning/30 rounded-md p-3 text-sm space-y-2">
                                        <p class="font-medium text-charcoal">
                                            Your landlord proposed {{ $activeRenewalRequest->alternative_duration_months }} month(s) instead{{ $activeRenewalRequest->alternative_new_end_date ? ', ending '.$activeRenewalRequest->alternative_new_end_date->format('d M Y') : '' }}.
                                        </p>
                                        @if ($activeRenewalRequest->alternative_note)
                                            <p class="text-brand-slate">{{ $activeRenewalRequest->alternative_note }}</p>
                                        @endif
                                        <div class="flex gap-3">
                                            <button wire:click="respondToRenewalAlternative({{ $activeRenewalRequest->id }}, true)" class="text-sm text-success hover:underline">Accept These Terms</button>
                                            <button wire:click="respondToRenewalAlternative({{ $activeRenewalRequest->id }}, false)" wire:confirm="Decline the proposed terms?" class="text-sm text-danger hover:underline">Decline</button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($renewalHistory->isNotEmpty())
                            <div class="pt-2 border-t border-brand-border">
                                <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider mb-2">History</p>
                                <div class="space-y-2">
                                    @foreach ($renewalHistory as $renewal)
                                        <div class="flex items-start justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0">
                                            <p class="text-charcoal">{{ $renewal->requested_duration_months }} month(s) <span class="text-xs text-brand-slate">({{ $renewal->created_at->format('d M Y') }})</span></p>
                                            <x-status-badge :status="$renewal->status" />
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($changeRequestHistory->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-charcoal">Change Requests</h3>
                        <div class="space-y-2">
                            @foreach ($changeRequestHistory as $request)
                                <div class="flex items-start justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0">
                                    <div>
                                        <p class="text-charcoal">{{ ucfirst(str_replace('_', ' ', $request->category)) }} <span class="text-xs text-brand-slate">({{ $request->created_at->format('d M Y') }})</span></p>
                                        <p class="text-xs text-brand-slate">{{ $request->requested_change }}</p>
                                        @if ($request->decision_note)
                                            <p class="text-xs text-brand-slate mt-0.5">{{ $request->decision_note }}</p>
                                        @endif
                                    </div>
                                    <x-status-badge :status="$request->status" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($activeNotice || $noticeHistory->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-charcoal">Tenancy Notice</h3>

                        @if ($activeNotice)
                            <div class="border border-warning/30 bg-warning/5 rounded-md p-4 space-y-2">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-charcoal">
                                            {{ ucfirst(str_replace('_', ' ', $activeNotice->reason_category)) }}
                                        </p>
                                        <p class="text-xs text-brand-slate mt-0.5">
                                            Issued {{ $activeNotice->notice_date->format('d M Y') }}
                                            @if ($activeNotice->proposed_vacate_date)
                                                &middot; Proposed vacate date {{ $activeNotice->proposed_vacate_date->format('d M Y') }}
                                            @endif
                                        </p>
                                    </div>
                                    <x-status-badge :status="$activeNotice->displayStatus()" />
                                </div>

                                @if ($activeNotice->reason_details)
                                    <p class="text-sm text-brand-slate">{{ $activeNotice->reason_details }}</p>
                                @endif

                                @foreach ($activeNotice->documents as $document)
                                    <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="block text-xs text-teal hover:underline">{{ $document->original_filename }}</a>
                                @endforeach

                                @if ($activeNotice->tenant_response_type)
                                    <div class="bg-white rounded-md p-3 text-sm">
                                        <p class="font-medium text-charcoal">Your response: {{ ucfirst(str_replace('_', ' ', $activeNotice->tenant_response_type)) }}</p>
                                        @if ($activeNotice->tenant_response_note)
                                            <p class="text-brand-slate mt-1">{{ $activeNotice->tenant_response_note }}</p>
                                        @endif
                                    </div>
                                @elseif (! $activeNotice->acknowledged_at)
                                    <button wire:click="acknowledgeNotice({{ $activeNotice->id }})" class="text-sm text-teal hover:underline">Acknowledge Receipt</button>
                                @endif

                                @if ($activeNotice->isOpen() && ! $activeNotice->tenant_response_type)
                                    <div class="flex flex-wrap gap-3 pt-2 border-t border-warning/20">
                                        <button wire:click="openDisputeForm" class="text-sm text-danger hover:underline">Dispute This Notice</button>
                                        <button wire:click="openRequestTimeForm" class="text-sm text-teal hover:underline">Request More Time</button>
                                        <button wire:click="openRequestExtensionForm" class="text-sm text-teal hover:underline">Request Agreement Extension</button>
                                    </div>
                                @endif

                                @if ($showDisputeForm)
                                    <form wire:submit="submitDispute({{ $activeNotice->id }})" class="space-y-2 pt-2">
                                        <x-input-label for="dispute_note" value="Why do you disagree with this notice?" />
                                        <textarea id="dispute_note" wire:model="dispute_note" rows="3" class="block w-full rounded-md border-brand-border text-sm"></textarea>
                                        <x-input-error :messages="$errors->get('dispute_note')" class="mt-1" />
                                        <div class="flex gap-3">
                                            <x-primary-button type="submit">Submit Dispute</x-primary-button>
                                            <button type="button" wire:click="cancelDisputeForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                        </div>
                                    </form>
                                @endif

                                @if ($showRequestTimeForm)
                                    <form wire:submit="submitRequestTime({{ $activeNotice->id }})" class="space-y-2 pt-2">
                                        <x-input-label for="requested_new_vacate_date" value="Date you're requesting" />
                                        <x-text-input id="requested_new_vacate_date" type="date" wire:model="requested_new_vacate_date" class="mt-1 block w-full max-w-xs" />
                                        <x-input-error :messages="$errors->get('requested_new_vacate_date')" class="mt-1" />
                                        <textarea wire:model="request_time_note" rows="2" placeholder="Why do you need more time? (optional)" class="block w-full rounded-md border-brand-border text-sm"></textarea>
                                        <div class="flex gap-3">
                                            <x-primary-button type="submit">Submit Request</x-primary-button>
                                            <button type="button" wire:click="cancelRequestTimeForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                        </div>
                                    </form>
                                @endif

                                @if ($showRequestExtensionForm)
                                    <form wire:submit="submitRequestExtension({{ $activeNotice->id }})" class="space-y-2 pt-2">
                                        <x-input-label for="request_extension_note" value="Why would you like to renew your agreement? (optional)" />
                                        <textarea id="request_extension_note" wire:model="request_extension_note" rows="3" class="block w-full rounded-md border-brand-border text-sm"></textarea>
                                        <div class="flex gap-3">
                                            <x-primary-button type="submit">Submit Request</x-primary-button>
                                            <button type="button" wire:click="cancelRequestExtensionForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        @endif

                        @if ($noticeHistory->isNotEmpty())
                            <div class="pt-2 border-t border-brand-border">
                                <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider mb-2">History</p>
                                <div class="space-y-2">
                                    @foreach ($noticeHistory as $notice)
                                        <div class="flex items-start justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0">
                                            <div>
                                                <p class="text-charcoal">{{ ucfirst(str_replace('_', ' ', $notice->reason_category)) }} <span class="text-xs text-brand-slate">({{ $notice->notice_date->format('d M Y') }})</span></p>
                                                @if ($notice->decision)
                                                    <p class="text-xs text-brand-slate">Decision: {{ ucfirst(str_replace('_', ' ', $notice->decision)) }}</p>
                                                @endif
                                            </div>
                                            <x-status-badge :status="$notice->displayStatus()" />
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($documents->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border">
                        <div class="px-5 py-4 border-b border-brand-border">
                            <h3 class="text-sm font-semibold text-brand-slate">Documents</h3>
                        </div>
                        <div class="divide-y divide-gray-100">
                            @foreach ($documents as $document)
                                <div class="px-5 py-3 flex items-center justify-between gap-3 text-sm">
                                    <div>
                                        <p class="text-charcoal font-medium">{{ $document->original_filename }}</p>
                                        <p class="text-brand-slate text-xs">{{ $categoryLabels[$document->category] ?? $document->category }} · {{ $document->created_at->format('d M Y') }}</p>
                                    </div>
                                    <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="text-teal hover:underline text-xs font-medium shrink-0">View / Download</a>
                                </div>
                            @endforeach
                        </div>
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
