<div>
    @if ($justCreated)
        @push('header-back-url'){{ route('manage.tenancies.index') }}@endpush
    @endif

    <x-slot name="header">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-semibold text-lg sm:text-xl text-charcoal leading-tight break-words">{{ $tenancy->tenant->name }}'s tenancy</h2>
                <x-status-badge :status="$tenancy->status" />
            </div>
            <p class="mt-1.5 flex items-center gap-1.5 text-xs sm:text-sm text-brand-slate">
                <svg class="h-3.5 w-3.5 sm:h-4 sm:w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                </svg>
                {{ $tenancy->house->block->property->name }} &middot; Unit {{ $tenancy->house->house_number }} / {{ $tenancy->house->block->name }}
            </p>
            <p class="mt-1 flex items-center gap-1.5 text-xs sm:text-sm text-brand-slate">
                <svg class="h-3.5 w-3.5 sm:h-4 sm:w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                </svg>
                Moved in {{ $tenancy->move_in_date->format('d F Y') }} &middot; {{ $tenancy->lease_end_date ? 'Lease expiry: '.$tenancy->lease_end_date->format('d F Y') : 'No fixed lease expiry' }}
            </p>
        </div>
    </x-slot>

    <div class="p-6 space-y-6">
        {{-- These actions live here, not in the header slot above — Livewire's
             wire:id reactive boundary only wraps the layout's main $slot, not
             $header (they're rendered as two separate blocks in layouts/admin.blade.php),
             so any wire:click placed in the header slot silently never fires. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                @can('update', $tenancy)
                    @if (in_array($tenancy->status, \App\Models\Tenancy::OCCUPYING_STATUSES))
                        <button
                            type="button"
                            onclick="document.getElementById('rent-section').open = true; document.getElementById('rent-section').scrollIntoView({behavior: 'smooth', block: 'start'});"
                            class="text-xs sm:text-sm text-white bg-navy rounded-md px-2 py-1 sm:px-3 sm:py-1.5 hover:bg-navy-dark"
                        >Record Rent</button>
                    @endif
                @endcan
                @can('view', $tenancy)
                    <button
                        type="button"
                        onclick="document.getElementById('contract-section').open = true; document.getElementById('contract-section').scrollIntoView({behavior: 'smooth', block: 'start'});"
                        class="text-xs sm:text-sm text-charcoal border border-brand-border rounded-md px-2 py-1 sm:px-3 sm:py-1.5 hover:bg-offwhite"
                    >Manage Contract</button>
                    <button wire:click="downloadTenancyReport" class="text-xs sm:text-sm text-brand-slate border border-brand-border rounded-md px-2 py-1 sm:px-3 sm:py-1.5 hover:bg-offwhite">
                        Download Record
                    </button>
                @endcan
            </div>
            <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                @can('update', $tenancy)
                    @if (in_array($tenancy->status, \App\Models\Tenancy::OCCUPYING_STATUSES))
                        @if (! $activeNotice)
                            <button wire:click="toggleGiveNoticeForm" class="text-xs sm:text-sm text-charcoal border border-brand-border rounded-md px-2 py-1 sm:px-3 sm:py-1.5 hover:bg-offwhite">
                                Give Notice
                            </button>
                        @endif
                        <button wire:click="$toggle('showMoveOutForm')" class="text-xs sm:text-sm text-danger border border-red-200 rounded-md px-2 py-1 sm:px-3 sm:py-1.5">
                            End Tenancy
                        </button>
                    @endif
                @endcan
            </div>
        </div>

        @if ($justCreated)
            <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="bg-green-50 border border-green-200 rounded-md px-4 py-3">
                <p class="text-sm font-medium text-green-800">Tenant added successfully</p>
                <p class="text-sm text-green-700 mt-0.5">The tenancy is active. Review the details below to manage rent, deposits and documents.</p>
            </div>
        @endif
        @if (session('status'))
            <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="bg-red-50 border border-red-200 text-danger text-sm rounded-md px-4 py-2">
                {{ session('error') }}
                @if (str_contains(session('error'), 'Contract Templates'))
                    <a href="{{ route('manage.contract-templates.index') }}" wire:navigate class="underline font-medium hover:text-red-900">Go to Contract Templates →</a>
                @endif
            </div>
        @endif

        @can('update', $tenancy)
            @php $pendingDueDayRequest = $tenancy->pendingDueDayChangeRequest(); @endphp
            @if ($pendingDueDayRequest)
                <div class="bg-white shadow-sm rounded-lg border border-warning/30 p-5 flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-charcoal">Due Date Change Requested</p>
                        <p class="text-sm text-brand-slate">
                            {{ $tenancy->tenant->name }} wants to move rent due date to the {{ \App\Models\Tenancy::ordinalDay($pendingDueDayRequest->requested_due_day) }} —
                            this would remove their grace period before a late fee applies.
                        </p>
                        @if ($pendingDueDayRequest->reason)
                            <p class="text-xs text-brand-slate mt-1">"{{ $pendingDueDayRequest->reason }}"</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <button wire:click="acceptDueDayChangeRequest({{ $pendingDueDayRequest->id }})" wire:confirm="Move {{ $tenancy->tenant->name }}'s due date to the {{ \App\Models\Tenancy::ordinalDay($pendingDueDayRequest->requested_due_day) }}? This removes their grace period." class="text-success hover:underline text-xs font-medium">Accept</button>
                        <button
                            x-data
                            @click="let reason = prompt('Reason for declining (optional):'); if (reason === null) return; $wire.rejectDueDayChangeRequest({{ $pendingDueDayRequest->id }}, reason)"
                            class="text-danger hover:underline text-xs font-medium"
                        >Reject</button>
                    </div>
                </div>
            @endif
        @endcan

        @if ($showGiveNoticeForm)
            <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'start'})" class="bg-white shadow-sm rounded-lg border border-brand-border p-5 space-y-4">
                <h3 class="text-sm font-semibold text-charcoal">Give Notice</h3>

                @if ($noticeCount > 0)
                    <div class="rounded-md bg-warning/10 border border-warning/30 text-charcoal text-sm px-4 py-2">
                        This tenant has already received {{ $noticeCount }} {{ $noticeCount === 1 ? 'notice' : 'notices' }} before. Review the history below before issuing another.
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="notice_reason_category" value="Reason for Notice" />
                        <select id="notice_reason_category" wire:model="notice_reason_category" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                            <option value="rent_arrears">Rent arrears</option>
                            <option value="noise_complaints">Repeated noise complaints</option>
                            <option value="property_damage">Property damage</option>
                            <option value="breach_of_agreement">Breach of tenancy agreement</option>
                            <option value="other">Other</option>
                        </select>
                        <x-input-error :messages="$errors->get('notice_reason_category')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="notice_date" value="Notice Date" />
                        <x-text-input id="notice_date" type="date" wire:model="notice_date" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('notice_date')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="notice_period_days" value="Notice Period (days)" />
                    <x-text-input id="notice_period_days" type="number" min="0" wire:model="notice_period_days" class="mt-1 block w-full max-w-xs" />
                    <p class="text-xs text-brand-slate mt-1">Proposed vacate date is calculated from the notice date plus this many days.</p>
                    <x-input-error :messages="$errors->get('notice_period_days')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="notice_reason_details" value="Explanation / Details (optional)" />
                    <textarea id="notice_reason_details" wire:model="notice_reason_details" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                    <x-input-error :messages="$errors->get('notice_reason_details')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="notice_evidence" value="Supporting Evidence (optional, PDF/JPG/PNG)" />
                    <input type="file" id="notice_evidence" wire:model="notice_evidence" class="mt-1 block w-full text-sm">
                    <x-input-error :messages="$errors->get('notice_evidence')" class="mt-1" />
                </div>

                <div class="flex gap-3">
                    <x-primary-button wire:click="issueNotice">Issue Notice</x-primary-button>
                    <x-secondary-button wire:click="toggleGiveNoticeForm">Cancel</x-secondary-button>
                </div>
            </div>
        @endif

        @if ($showMoveOutForm)
            <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'start'})" class="bg-white shadow-sm rounded-lg border border-brand-border p-5 space-y-4">
                <h3 class="text-sm font-semibold text-charcoal">End Tenancy</h3>
                <div class="flex flex-col sm:flex-row gap-2 sm:gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" wire:model="moveOutStatus" value="completed" /> Completed (lease ran its course)
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" wire:model="moveOutStatus" value="terminated" /> Terminated (ended early)
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="actualMoveOutDate" value="Actual Move-out Date" />
                        <x-text-input id="actualMoveOutDate" type="date" wire:model="actualMoveOutDate" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('actualMoveOutDate')" class="mt-1" />
                    </div>
                    @if ($moveOutStatus === 'terminated')
                        <div>
                            <x-input-label for="terminationReason" value="Reason for termination" />
                            <x-text-input id="terminationReason" type="text" wire:model="terminationReason" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('terminationReason')" class="mt-1" />
                        </div>
                    @endif
                </div>

                <div>
                    <x-input-label for="endTenancyNotes" value="Notes (optional)" />
                    <textarea id="endTenancyNotes" wire:model="endTenancyNotes" rows="2" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                </div>

                <div class="border-t border-brand-border pt-4">
                    <h4 class="text-xs font-semibold text-brand-slate uppercase tracking-wider mb-2">Final Deposit Refund (optional)</h4>
                    <p class="text-xs text-brand-slate mb-3">
                        Currently held: <span class="font-medium text-charcoal">KSh {{ number_format($tenancy->depositHeldBalance()) }}</span>.
                        Need to deduct for damage first? Record that from the Deposit section below before (or after) ending the tenancy — deductions
                        are no longer tied to this form. Leave the amount blank to settle the refund later.
                    </p>
                    <div>
                        <x-input-label for="finalRefundAmount" value="Refund Amount (KSh)" />
                        <x-text-input id="finalRefundAmount" type="number" step="0.01" wire:model="finalRefundAmount" class="mt-1 block w-full max-w-xs" />
                        <x-input-error :messages="$errors->get('finalRefundAmount')" class="mt-1" />
                    </div>
                </div>

                <div class="border-t border-brand-border pt-4">
                    <h4 class="text-xs font-semibold text-brand-slate uppercase tracking-wider mb-2">Supporting Document (optional)</h4>
                    <livewire:documents.manager :model="$tenancy" category="other" :key="'end-tenancy-docs-'.$tenancy->id" />
                </div>

                @if ($tenancy->balance() > 0)
                    <div class="border border-red-200 bg-red-50 rounded-md p-4 space-y-3">
                        <p class="text-sm font-semibold text-danger">
                            This tenant still owes KSh {{ number_format($tenancy->balance()) }} in unpaid rent.
                        </p>
                        <label class="flex items-start gap-2 text-sm text-charcoal">
                            <input type="checkbox" wire:model="acknowledgeUnpaidBalance" class="mt-0.5 rounded border-red-300" />
                            <span>I understand and want to end this tenancy anyway. This will be recorded in the audit log.</span>
                        </label>
                        <x-input-error :messages="$errors->get('acknowledgeUnpaidBalance')" class="mt-1" />
                    </div>
                @endif

                <div class="flex gap-3">
                    <x-primary-button wire:click="reviewTermination">Review &amp; Continue</x-primary-button>
                    <x-secondary-button wire:click="$set('showMoveOutForm', false)">Cancel</x-secondary-button>
                </div>
            </div>
        @endif

        @if ($showTerminationSummary && $terminationSummary)
            <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'start'})" class="bg-white shadow-sm rounded-lg border border-danger/30 p-5 space-y-4">
                <h3 class="text-sm font-semibold text-charcoal">Confirm Termination</h3>
                <p class="text-sm text-brand-slate">Review everything below before formally ending this tenancy. Nothing has been written yet.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider">Tenant / Unit</p>
                        <p class="text-charcoal">{{ $terminationSummary['tenant']->name }} — {{ $terminationSummary['house']->house_number }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider">Agreed Final Date</p>
                        <p class="text-charcoal">{{ $terminationSummary['agreedFinalDate']?->format('d M Y') ?? $actualMoveOutDate }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider">Outstanding Rent</p>
                        <p class="{{ $terminationSummary['rentBalance'] > 0 ? 'text-danger' : 'text-charcoal' }}">KSh {{ number_format($terminationSummary['rentBalance'], 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider">Deposit</p>
                        <p class="text-charcoal">
                            Held KSh {{ number_format($terminationSummary['depositHeld']) }}
                            @if ($terminationSummary['depositOutstanding'] > 0)
                                &middot; <span class="text-warning">KSh {{ number_format($terminationSummary['depositOutstanding']) }} still unpaid</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider">Notice History</p>
                        <p class="text-charcoal">{{ $terminationSummary['noticeHistory']->count() }} notice(s) on record</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider">Agreement History</p>
                        <p class="text-charcoal">{{ $terminationSummary['contractHistory']->count() }} version(s) on record</p>
                    </div>
                </div>

                @php
                    $hasTenancySpecific = $terminationSummary['openMaintenanceOnThisTenancyCount'] > 0
                        || $terminationSummary['unresolvedComplaintsOnThisTenancyCount'] > 0
                        || $terminationSummary['unresolvedDisputedDeductionCount'] > 0;
                    $hasUnitOrTenantOther = $terminationSummary['openMaintenanceOtherOnUnitCount'] > 0
                        || $terminationSummary['unresolvedComplaintsOtherByTenantCount'] > 0;
                @endphp
                @if ($hasTenancySpecific)
                    <div class="border border-warning/30 bg-warning/10 rounded-md p-3 text-sm text-charcoal">
                        <p class="font-medium">There are unresolved items on this tenancy:</p>
                        <ul class="list-disc list-inside mt-1">
                            @if ($terminationSummary['openMaintenanceOnThisTenancyCount'] > 0)
                                <li>{{ $terminationSummary['openMaintenanceOnThisTenancyCount'] }} open maintenance task(s)</li>
                            @endif
                            @if ($terminationSummary['unresolvedComplaintsOnThisTenancyCount'] > 0)
                                <li>{{ $terminationSummary['unresolvedComplaintsOnThisTenancyCount'] }} unresolved maintenance request(s)</li>
                            @endif
                            @if ($terminationSummary['unresolvedDisputedDeductionCount'] > 0)
                                <li>{{ $terminationSummary['unresolvedDisputedDeductionCount'] }} disputed deposit deduction(s) awaiting resolution</li>
                            @endif
                        </ul>
                    </div>
                @endif
                @if ($hasUnitOrTenantOther)
                    <div class="border border-brand-border bg-offwhite rounded-md p-3 text-sm text-brand-slate">
                        <p class="font-medium text-charcoal">Other unresolved items nearby (not specific to this tenancy — may belong to a previous occupant or a different tenancy):</p>
                        <ul class="list-disc list-inside mt-1">
                            @if ($terminationSummary['openMaintenanceOtherOnUnitCount'] > 0)
                                <li>{{ $terminationSummary['openMaintenanceOtherOnUnitCount'] }} open maintenance task(s) on this unit</li>
                            @endif
                            @if ($terminationSummary['unresolvedComplaintsOtherByTenantCount'] > 0)
                                <li>{{ $terminationSummary['unresolvedComplaintsOtherByTenantCount'] }} unresolved request(s) elsewhere on this tenant's record</li>
                            @endif
                        </ul>
                    </div>
                @endif

                <div class="flex gap-3">
                    <x-primary-button wire:click="confirmTermination">Confirm Termination</x-primary-button>
                    <x-secondary-button wire:click="cancelTerminationSummary">Back</x-secondary-button>
                </div>
            </div>
        @endif

        <div id="summary" class="scroll-mt-32 grid grid-cols-2 md:grid-cols-4 gap-4">
            <x-stat-tile label="Monthly Rent" value="KSh {{ number_format($tenancy->monthly_rent) }}">
                Due on the {{ \App\Models\Tenancy::ordinalDay($tenancy->dueDay()) }}
            </x-stat-tile>
            <x-stat-tile
                label="Rent Balance"
                value="KSh {{ number_format($tenancy->balance()) }}"
                tone="{{ $tenancy->balance() > 0 ? 'critical' : 'good' }}"
                href="#rent-section"
                onclick="document.getElementById('rent-section').open = true;"
            >{{ $tenancy->balance() > 0 ? 'Outstanding' : 'Fully paid' }}</x-stat-tile>
            <x-stat-tile
                label="Deposit Outstanding"
                value="KSh {{ number_format($depositOutstanding) }}"
                tone="{{ $depositOutstanding > 0 ? 'warning' : 'good' }}"
                href="#deposit-section"
                onclick="document.getElementById('deposit-section').open = true;"
            >{{ $depositOutstanding > 0 ? 'Outstanding' : 'Fully paid' }}</x-stat-tile>
            <x-stat-tile
                label="Deposit Held"
                value="KSh {{ number_format($depositHeld) }}"
                href="#deposit-section"
                onclick="document.getElementById('deposit-section').open = true;"
            >Currently held</x-stat-tile>
        </div>

        {{-- The tenancy is never only its rent balance — a landlord who has
             just confirmed the tenant exists shouldn't also have to take in
             rent charges, deposit transactions, notices and a contract all
             at once. Everything below is collapsible; the summary above and
             the quick actions above that stay visible at all times. --}}
        <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5">
            <h3 class="text-sm font-semibold text-charcoal mb-3">Manage Tenancy</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <button type="button" onclick="document.getElementById('rent-section').open = true; document.getElementById('rent-section').scrollIntoView({behavior: 'smooth', block: 'start'});" class="text-left border border-brand-border rounded-md p-3 hover:border-teal/40 hover:shadow-sm transition">
                    <p class="text-sm font-medium text-charcoal">Rent and payments</p>
                    <p class="text-xs text-brand-slate mt-0.5">Record payments, view rent charges and payment history</p>
                </button>
                <button type="button" onclick="document.getElementById('deposit-section').open = true; document.getElementById('deposit-section').scrollIntoView({behavior: 'smooth', block: 'start'});" class="text-left border border-brand-border rounded-md p-3 hover:border-teal/40 hover:shadow-sm transition">
                    <p class="text-sm font-medium text-charcoal">Security deposit</p>
                    <p class="text-xs text-brand-slate mt-0.5">View deposit balances, record deductions and refunds</p>
                </button>
                <button type="button" onclick="document.getElementById('contract-section').open = true; document.getElementById('contract-section').scrollIntoView({behavior: 'smooth', block: 'start'});" class="text-left border border-brand-border rounded-md p-3 hover:border-teal/40 hover:shadow-sm transition">
                    <p class="text-sm font-medium text-charcoal">Contract and documents</p>
                    <p class="text-xs text-brand-slate mt-0.5">Generate agreements, track signatures and upload files</p>
                </button>
                <button type="button" onclick="document.getElementById('notices-section').open = true; document.getElementById('notices-section').scrollIntoView({behavior: 'smooth', block: 'start'});" class="text-left border border-brand-border rounded-md p-3 hover:border-teal/40 hover:shadow-sm transition">
                    <p class="text-sm font-medium text-charcoal">Notices and requests</p>
                    <p class="text-xs text-brand-slate mt-0.5">Manage notices, tenant requests and renewals</p>
                </button>
                <button type="button" onclick="document.getElementById('details-section').open = true; document.getElementById('details-section').scrollIntoView({behavior: 'smooth', block: 'start'});" class="text-left border border-brand-border rounded-md p-3 hover:border-teal/40 hover:shadow-sm transition md:col-span-2">
                    <p class="text-sm font-medium text-charcoal">Tenancy details</p>
                    <p class="text-xs text-brand-slate mt-0.5">View or update tenancy information</p>
                </button>
            </div>
        </div>

        <details id="rent-section" class="scroll-mt-32 bg-white shadow-sm rounded-lg border border-brand-border open:shadow-md">
            <summary class="px-5 py-4 cursor-pointer select-none text-sm font-semibold text-charcoal">Rent and Payments</summary>

            @can('update', $tenancy)
                @if (in_array($tenancy->status, \App\Models\Tenancy::OCCUPYING_STATUSES))
                    <div class="px-5 pb-5">
                        <livewire:payments.record-payment :tenancy="$tenancy" :key="'record-payment-'.$tenancy->id" />
                    </div>
                @endif
            @endcan

            <div class="border-t border-brand-border">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h4 class="text-sm font-semibold text-brand-slate">Rent Charges</h4>
                    @if ($advanceCreditAvailable > 0)
                        <p class="text-xs text-teal mt-1">
                            This tenant has KSh {{ number_format($advanceCreditAvailable, 2) }} in advance credit from a prior overpayment — applied below, oldest charge first, to reduce what's still due. The original payment record is unchanged.
                        </p>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Period</th>
                                <th class="text-left px-5 py-2 font-medium">Due Date</th>
                                <th class="text-right px-5 py-2 font-medium">Expected</th>
                                <th class="text-right px-5 py-2 font-medium">Paid</th>
                                <th class="text-right px-5 py-2 font-medium">Balance</th>
                                <th class="text-right px-5 py-2 font-medium">Credit Applied</th>
                                <th class="text-right px-5 py-2 font-medium">Net Due</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="text-left px-5 py-2 font-medium">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($rentCharges as $charge)
                                @php
                                    $creditApplied = (float) ($creditAppliedByChargeId[$charge->id] ?? 0);
                                    $grossBalance = (float) $charge->amount_expected - (float) $charge->amount_paid;
                                    $netDue = max(0, $grossBalance - $creditApplied);
                                @endphp
                                <tr>
                                    <td class="px-5 py-2 text-charcoal">{{ \Carbon\Carbon::create()->month($charge->period_month)->format('F') }} {{ $charge->period_year }}</td>
                                    <td class="px-5 py-2 text-brand-slate">{{ $charge->due_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2 text-right text-brand-slate">
                                        KSh {{ number_format($charge->amount_expected, 2) }}
                                        @if ($charge->late_fee_amount > 0)
                                            <span class="block text-xs text-warning whitespace-nowrap">+KSh {{ number_format($charge->late_fee_amount, 2) }} late fee</span>
                                            @can('update', $tenancy)
                                                <button
                                                    wire:click="waiveLateFee({{ $charge->id }})"
                                                    wire:confirm="Waive this KSh {{ number_format($charge->late_fee_amount, 2) }} late fee for {{ $tenancy->tenant->name }}?{{ $charge->status === 'paid' ? ' This charge is already fully paid, so the tenant will show a credit for this amount.' : '' }}"
                                                    class="text-xs text-teal hover:underline"
                                                >Waive</button>
                                            @endcan
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-right text-brand-slate">KSh {{ number_format($charge->amount_paid, 2) }}</td>
                                    <td class="px-5 py-2 text-right {{ $grossBalance > 0 ? 'text-danger' : 'text-brand-slate' }}">
                                        KSh {{ number_format($grossBalance, 2) }}
                                    </td>
                                    <td class="px-5 py-2 text-right {{ $creditApplied > 0 ? 'text-teal' : 'text-gray-400' }}">
                                        {{ $creditApplied > 0 ? 'KSh '.number_format($creditApplied, 2) : '—' }}
                                    </td>
                                    <td class="px-5 py-2 text-right {{ $netDue > 0 ? 'text-danger' : 'text-brand-slate' }} font-medium">
                                        KSh {{ number_format($netDue, 2) }}
                                        @if ($grossBalance > 0 && $netDue <= 0)
                                            <span class="block text-[11px] text-teal font-normal">Covered by advance credit</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2"><x-status-badge :status="$charge->status" /></td>
                                    <td class="px-5 py-2 text-brand-slate max-w-xs truncate" title="{{ $charge->notes }}">{{ $charge->notes }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-5 py-6 text-center text-gray-400">No rent charges generated yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="border-t border-brand-border">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h4 class="text-sm font-semibold text-brand-slate">Payment History</h4>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Date</th>
                                <th class="text-left px-5 py-2 font-medium">Method</th>
                                <th class="text-left px-5 py-2 font-medium">Reference</th>
                                <th class="text-right px-5 py-2 font-medium">Amount</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                @can('update', $tenancy)
                                    <th class="px-5 py-2"></th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($payments as $payment)
                                <tr>
                                    <td class="px-5 py-2 text-brand-slate">{{ $payment->payment_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2 text-brand-slate">
                                        {{ ucfirst($payment->method) }}
                                        @if ($payment->collection_mode === 'direct')
                                            <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-teal/10 text-teal align-middle" title="Settled straight into your own paybill/till — Kats never held this money, so it's never part of what's disbursed to you.">Direct</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-brand-slate">{{ $payment->reference_number ?? '—' }}</td>
                                    <td class="px-5 py-2 text-right text-charcoal">KSh {{ number_format($payment->amount, 2) }}</td>
                                    <td class="px-5 py-2">
                                        <x-status-badge :status="$payment->status" />
                                        @if ($payment->isDisputed() && $payment->dispute_reason)
                                            <span class="block text-xs text-brand-slate mt-0.5">"{{ $payment->dispute_reason }}"</span>
                                        @endif
                                        @if ($payment->isTenantSubmittedOffline())
                                            <span class="block text-xs text-brand-slate mt-0.5">Tenant-reported</span>
                                        @endif
                                    </td>
                                    @can('update', $tenancy)
                                        <td class="px-5 py-2 text-right whitespace-nowrap">
                                            @if ($payment->needsLandlordConfirmation())
                                                <button wire:click="confirmPayment({{ $payment->id }})" wire:confirm="Confirm this KSh {{ number_format($payment->amount, 2) }} payment? It will be added to the tenant's paid amount." class="text-success hover:underline text-xs font-medium">Confirm</button>
                                                <button
                                                    x-data
                                                    @click="let reason = prompt('Reason for disputing this payment:'); if (! reason) return; $wire.disputePayment({{ $payment->id }}, reason)"
                                                    class="text-danger hover:underline text-xs font-medium ml-2"
                                                >Dispute</button>
                                            @elseif ($payment->status === 'confirmed' && $payment->isTenantSubmittedOffline())
                                                <button
                                                    x-data
                                                    @click="let reason = prompt('Reason for disputing this payment:'); if (! reason) return; $wire.disputePayment({{ $payment->id }}, reason)"
                                                    class="text-danger hover:underline text-xs font-medium"
                                                >Dispute</button>
                                            @endif
                                        </td>
                                    @endcan
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-6 text-center text-gray-400">No payments recorded.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details id="deposit-section" class="scroll-mt-32 bg-white shadow-sm rounded-lg border border-brand-border open:shadow-md" @if ($showDeductionForm || $showRefundForm || $voidingDeductionId) open @endif>
            <summary class="px-5 py-4 cursor-pointer select-none flex items-center gap-2">
                <span class="text-sm font-semibold text-charcoal">Security Deposit</span>
                <x-status-badge :status="$tenancy->depositRefundStatus()" />
            </summary>

            @can('update', $tenancy)
                @if (in_array($tenancy->status, \App\Models\Tenancy::OCCUPYING_STATUSES))
                    <div class="px-5 pb-5 border-b border-brand-border">
                        <livewire:deposits.record-deposit :tenancy="$tenancy" :key="'record-deposit-'.$tenancy->id" />
                    </div>
                @endif
            @endcan

            <div class="px-5 py-4 border-b border-brand-border flex items-center justify-end flex-wrap gap-2">
                @can('update', $tenancy)
                    <div class="flex items-center gap-3">
                        @if ($depositOutstanding > 0)
                            <button wire:click="requestOutstandingDeposit" class="text-xs text-teal hover:underline">Request Outstanding Deposit</button>
                        @endif
                        @if ($depositHeld > 0)
                            <button wire:click="$toggle('showDeductionForm')" class="text-xs text-teal hover:underline">Record Deduction</button>
                            <button wire:click="$toggle('showRefundForm')" class="text-xs text-teal hover:underline">Record Refund</button>
                        @endif
                    </div>
                @endcan
            </div>

            <div class="px-5 py-4 border-b border-brand-border grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
                <div>
                    <p class="text-xs text-brand-slate">Required</p>
                    <p class="font-medium text-charcoal">KSh {{ number_format($tenancy->deposit_required) }}</p>
                </div>
                <div>
                    <p class="text-xs text-brand-slate">Paid</p>
                    <p class="font-medium text-charcoal">KSh {{ number_format($tenancy->deposit_paid) }}</p>
                </div>
                <div>
                    <p class="text-xs text-brand-slate">Outstanding</p>
                    <p class="font-medium {{ $depositOutstanding > 0 ? 'text-warning' : 'text-charcoal' }}">KSh {{ number_format($depositOutstanding) }}</p>
                </div>
                <div>
                    <p class="text-xs text-brand-slate">Deducted</p>
                    <p class="font-medium {{ $depositDeducted > 0 ? 'text-danger' : 'text-charcoal' }}">KSh {{ number_format($depositDeducted) }}</p>
                </div>
                <div>
                    <p class="text-xs text-brand-slate">Held Now</p>
                    <p class="font-medium text-charcoal">KSh {{ number_format($depositHeld) }}</p>
                </div>
            </div>

            @if ($tenancy->deposit_request_sent_at)
                <p class="px-5 py-2 text-xs text-brand-slate border-b border-brand-border">
                    Outstanding deposit requested from the tenant on {{ $tenancy->deposit_request_sent_at->format('d M Y H:i') }}.
                </p>
            @endif

            @if ($showDeductionForm)
                <form wire:submit="recordDeduction" class="p-5 border-b border-brand-border space-y-3">
                    <p class="text-xs text-brand-slate">
                        Currently held: KSh {{ number_format($depositHeld) }}.
                        @if ($deduction_amount !== '' && is_numeric($deduction_amount))
                            Resulting held balance after this deduction: <strong>KSh {{ number_format(max(0, $depositHeld - (float) $deduction_amount)) }}</strong>.
                        @endif
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="deduction_amount" value="Deduction Amount (KSh)" />
                            <x-text-input id="deduction_amount" type="number" step="0.01" wire:model.live="deduction_amount" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('deduction_amount')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deduction_date" value="Date" />
                            <x-text-input id="deduction_date" type="date" wire:model="deduction_date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('deduction_date')" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="deduction_reason" value="Reason" />
                        <textarea id="deduction_reason" wire:model="deduction_reason" rows="2" class="mt-1 block w-full rounded-md border-brand-border" placeholder="e.g. Repair of broken window in bedroom"></textarea>
                        <x-input-error :messages="$errors->get('deduction_reason')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="deduction_evidence" value="Supporting Evidence (optional, PDF/JPG/PNG)" />
                        <input type="file" id="deduction_evidence" wire:model="deduction_evidence" class="mt-1 block w-full text-sm">
                        <x-input-error :messages="$errors->get('deduction_evidence')" class="mt-1" />
                    </div>
                    <div class="flex gap-3">
                        <x-primary-button type="submit">Save Deduction</x-primary-button>
                        <x-secondary-button type="button" wire:click="cancelDeductionForm">Cancel</x-secondary-button>
                    </div>
                </form>
            @endif

            @if ($showRefundForm)
                <form wire:submit="recordRefund" class="p-5 border-b border-brand-border space-y-3">
                    <p class="text-xs text-brand-slate">
                        Currently held: KSh {{ number_format($depositHeld) }}.
                        @if ($refund_amount !== '' && is_numeric($refund_amount))
                            Resulting held balance after this refund: <strong>KSh {{ number_format(max(0, $depositHeld - (float) $refund_amount)) }}</strong>.
                        @endif
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="refund_amount" value="Refund Amount (KSh)" />
                            <x-text-input id="refund_amount" type="number" step="0.01" wire:model.live="refund_amount" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('refund_amount')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="refund_date" value="Date" />
                            <x-text-input id="refund_date" type="date" wire:model="refund_date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('refund_date')" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="refund_notes" value="Notes (optional)" />
                        <x-text-input id="refund_notes" type="text" wire:model="refund_notes" class="mt-1 block w-full" />
                    </div>
                    <div class="flex gap-3">
                        <x-primary-button type="submit">Save Refund</x-primary-button>
                        <x-secondary-button type="button" wire:click="cancelRefundForm">Cancel</x-secondary-button>
                    </div>
                </form>
            @endif

            @if (count($depositDeductions) > 0)
                <div class="px-5 py-3 border-b border-brand-border">
                    <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider mb-2">Deductions</p>
                    <div class="space-y-2">
                        @foreach ($depositDeductions as $deduction)
                            <div class="flex items-start justify-between gap-3 text-sm {{ $deduction->isVoided() ? 'opacity-50' : '' }}">
                                <div>
                                    <p class="text-charcoal">{{ $deduction->reason }}</p>
                                    <p class="text-xs text-brand-slate">
                                        {{ $deduction->deduction_date->format('d M Y') }}
                                        @if ($deduction->isVoided())
                                            &middot; Voided{{ $deduction->void_reason ? ': '.$deduction->void_reason : '' }}
                                        @endif
                                    </p>
                                    @if ($deduction->isDisputePending())
                                        <p class="text-xs text-warning font-medium mt-1">Disputed by tenant: {{ $deduction->dispute_reason }}</p>
                                    @elseif ($deduction->disputed_at)
                                        <p class="text-xs text-brand-slate mt-1">Dispute resolved — upheld</p>
                                    @endif
                                    @foreach ($deduction->documents as $document)
                                        <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="text-teal hover:underline text-xs">{{ $document->original_filename }}</a>
                                    @endforeach
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="{{ $deduction->isVoided() ? 'text-brand-slate line-through' : 'text-danger' }} font-medium">-KSh {{ number_format($deduction->amount) }}</p>
                                    @can('update', $tenancy)
                                        @if ($deduction->isDisputePending())
                                            <div class="mt-1 flex items-center gap-2 justify-end">
                                                <button wire:click="resolveDispute({{ $deduction->id }}, 'uphold')" class="text-xs text-teal hover:underline">Uphold</button>
                                                <button wire:click="resolveDispute({{ $deduction->id }}, 'void')" wire:confirm="Void this deduction?" class="text-xs text-danger hover:underline">Void</button>
                                            </div>
                                        @elseif (! $deduction->isVoided())
                                            @if ($voidingDeductionId === $deduction->id)
                                                <div class="mt-1 flex items-center gap-1">
                                                    <input type="text" wire:model="void_reason" placeholder="Reason for voiding" class="text-xs rounded-md border-brand-border">
                                                    <button wire:click="voidDeduction" class="text-xs text-danger hover:underline">Confirm</button>
                                                    <button wire:click="cancelVoidDeduction" class="text-xs text-brand-slate hover:underline">Cancel</button>
                                                </div>
                                            @else
                                                <button wire:click="startVoidDeduction({{ $deduction->id }})" class="text-xs text-brand-slate hover:underline">Void</button>
                                            @endif
                                        @endif
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Date</th>
                            <th class="text-left px-5 py-2 font-medium">Method</th>
                            <th class="text-right px-5 py-2 font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($depositPayments as $deposit)
                            <tr>
                                <td class="px-5 py-2 text-brand-slate">{{ $deposit->payment_date->format('d M Y') }}</td>
                                <td class="px-5 py-2 text-brand-slate">{{ ucfirst($deposit->method) }}</td>
                                <td class="px-5 py-2 text-right text-charcoal">KSh {{ number_format($deposit->amount) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-6 text-center text-gray-400">No deposit payments recorded.</td>
                            </tr>
                        @endforelse
                        @foreach ($depositRefunds as $refund)
                            <tr>
                                <td class="px-5 py-2 text-brand-slate">{{ $refund->refund_date->format('d M Y') }}</td>
                                <td class="px-5 py-2 text-danger">Refunded{{ $refund->notes ? ' ('.$refund->notes.')' : '' }}</td>
                                <td class="px-5 py-2 text-right text-danger">-KSh {{ number_format($refund->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        <details id="contract-section" class="scroll-mt-32 bg-white shadow-sm rounded-lg border border-brand-border open:shadow-md" @if ($showUploadForm) open @endif>
            <summary class="px-5 py-4 cursor-pointer select-none flex items-center gap-2">
                <span class="text-sm font-semibold text-charcoal">Contract and Documents</span>
                @if ($contract)
                    <x-status-badge :status="$contract->effectiveStatus()" />
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-navy-light text-navy">
                        {{ $contract->isLandlordUpload() ? 'Landlord Uploaded' : 'System Template' }}
                    </span>
                @endif
            </summary>

            <div class="px-5 pb-5">
                @can('update', $tenancy)
                    @if (! $contract)
                        <p class="text-sm text-brand-slate mb-3">No contract has been generated for this tenancy yet.</p>
                    @else
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4">
                            <div>
                                <p class="text-brand-slate">Version</p>
                                <p class="text-charcoal">{{ $contract->version }}</p>
                            </div>
                            @if ($contract->sent_at)
                                <div>
                                    <p class="text-brand-slate">Sent</p>
                                    <p class="text-charcoal">{{ $contract->sent_at->format('d M Y') }}</p>
                                </div>
                            @endif
                            @if ($contract->isSigned())
                                <div>
                                    <p class="text-brand-slate">Signed By</p>
                                    <p class="text-charcoal">{{ $contract->signed_by_name }}</p>
                                </div>
                                <div>
                                    <p class="text-brand-slate">Date Signed</p>
                                    <p class="text-charcoal">{{ $contract->signed_at->format('d M Y H:i') }}</p>
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-3 mb-3">
                            @if (in_array($contract->status, ['draft', 'generated']))
                                <button wire:click="sendContract" class="text-sm text-white bg-navy rounded-md px-3 py-1.5 hover:bg-navy-dark">Send to Tenant</button>
                            @elseif ($contract->status === 'awaiting_signature')
                                <button wire:click="sendContract" class="text-sm text-charcoal border border-brand-border rounded-md px-3 py-1.5 hover:bg-offwhite">Resend</button>
                            @endif
                            @if ($contract->document)
                                <button wire:click="downloadContract" class="text-sm text-teal hover:underline">Download</button>
                            @endif
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            wire:click="generateContract"
                            wire:confirm="{{ $contract ? 'This issues a new version '.($contract->version + 1).' and replaces v'.$contract->version.' as the current contract — v'.$contract->version.' stays on record as superseded, with its signing status and history intact. The new version still needs to be sent and signed separately. Continue?' : '' }}"
                            class="text-sm {{ $contract ? 'text-brand-slate hover:underline' : 'text-white bg-navy rounded-md px-3 py-1.5 hover:bg-navy-dark' }}"
                        >{{ $contract ? 'Issue a Revised Agreement' : 'Use System Template' }}</button>
                        <button wire:click="toggleUploadForm" class="text-sm text-charcoal border border-brand-border rounded-md px-3 py-1.5 hover:bg-offwhite">
                            Upload My Own
                        </button>
                    </div>

                    @if ($contractHistory->count() > 1)
                        <details class="mt-3">
                            <summary class="text-sm text-teal hover:underline cursor-pointer select-none">Version History ({{ $contractHistory->count() }})</summary>
                            <div class="overflow-x-auto">
                            <table class="min-w-full text-xs mt-2">
                                <thead class="text-brand-slate">
                                    <tr>
                                        <th class="text-left py-1 font-medium">Version</th>
                                        <th class="text-left py-1 font-medium">Status</th>
                                        <th class="text-left py-1 font-medium">Sent</th>
                                        <th class="text-left py-1 font-medium">Signed By</th>
                                        <th class="text-left py-1 font-medium">Signed At</th>
                                        <th class="text-left py-1 font-medium">Created</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($contractHistory as $version)
                                        <tr>
                                            <td class="py-1.5 text-charcoal font-medium">v{{ $version->version }}</td>
                                            <td class="py-1.5"><x-status-badge :status="$version->effectiveStatus()" /></td>
                                            <td class="py-1.5 text-brand-slate">{{ $version->sent_at?->format('d M Y H:i') ?? '—' }}</td>
                                            <td class="py-1.5 text-brand-slate">{{ $version->signed_by_name ?? '—' }}</td>
                                            <td class="py-1.5 text-brand-slate">{{ $version->signed_at?->format('d M Y H:i') ?? '—' }}</td>
                                            <td class="py-1.5 text-brand-slate">{{ $version->created_at->format('d M Y H:i') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        </details>
                    @endif

                    @if ($showUploadForm)
                        <form wire:submit="uploadContract" class="mt-3 flex items-center gap-3">
                            <input type="file" wire:model="contractFile" accept="application/pdf" class="text-sm" />
                            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="uploadContract">Save Uploaded Contract</x-primary-button>
                        </form>
                        <div wire:loading wire:target="contractFile" class="text-xs text-brand-slate mt-1">Uploading…</div>
                        <x-input-error :messages="$errors->get('contractFile')" class="mt-1" />
                    @endif
                @endcan
            </div>

            <div class="border-t border-brand-border p-5">
                <h4 class="text-sm font-semibold text-brand-slate mb-3">Lease Document</h4>
                <livewire:documents.manager :model="$tenancy" :key="'docs-tenancy-'.$tenancy->id" />
            </div>
        </details>

        <details id="notices-section" class="scroll-mt-32 bg-white shadow-sm rounded-lg border border-brand-border open:shadow-md" @if ($activeNotice || $decidingNoticeId || $withdrawingNoticeId || $decidingChangeRequestId || $decidingRenewalId || $activeRenewalRequest) open @endif>
            <summary class="px-5 py-4 cursor-pointer select-none text-sm font-semibold text-charcoal">Notices and Requests</summary>

            <div class="px-5 pb-5 space-y-4">
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-charcoal">Tenancy Notices</h4>

                    @if ($activeNotice)
                        <div class="border border-brand-border rounded-md p-4 space-y-2">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-charcoal">
                                        Notice #{{ $activeNotice->sequence_number }} — {{ ucfirst(str_replace('_', ' ', $activeNotice->reason_category)) }}
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

                            @if ($activeNotice->tenant_response_type)
                                <div class="bg-offwhite rounded-md p-3 text-sm">
                                    <p class="font-medium text-charcoal">Tenant response: {{ ucfirst(str_replace('_', ' ', $activeNotice->tenant_response_type)) }}</p>
                                    @if ($activeNotice->tenant_response_note)
                                        <p class="text-brand-slate mt-1">{{ $activeNotice->tenant_response_note }}</p>
                                    @endif
                                    @if ($activeNotice->requested_new_vacate_date)
                                        <p class="text-brand-slate mt-1">Requested vacate date: {{ $activeNotice->requested_new_vacate_date->format('d M Y') }}</p>
                                    @endif
                                </div>
                            @endif

                            @can('update', $tenancy)
                                <div class="flex items-center gap-3 pt-1">
                                    @if ($activeNotice->isAwaitingDecision())
                                        <button wire:click="startDecideNotice({{ $activeNotice->id }})" class="text-sm text-teal hover:underline">Decide</button>
                                    @endif
                                    <button wire:click="startWithdrawNotice({{ $activeNotice->id }})" class="text-sm text-brand-slate hover:underline">Withdraw Notice</button>
                                </div>
                            @endcan
                        </div>
                    @else
                        <p class="text-sm text-brand-slate">No active notice on this tenancy.</p>
                    @endif

                    @if ($withdrawingNoticeId)
                        <div class="border border-brand-border rounded-md p-4 space-y-2">
                            <x-input-label for="withdraw_reason" value="Reason for withdrawing (optional)" />
                            <textarea id="withdraw_reason" wire:model="withdraw_reason" rows="2" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                            <div class="flex gap-3">
                                <x-primary-button wire:click="withdrawNotice">Confirm Withdrawal</x-primary-button>
                                <x-secondary-button wire:click="cancelWithdrawNotice">Cancel</x-secondary-button>
                            </div>
                        </div>
                    @endif

                    @if ($decidingNoticeId && $decidingNotice)
                        <div class="border border-teal/30 bg-teal/5 rounded-md p-4 space-y-3">
                            <h4 class="text-sm font-semibold text-charcoal">Decide on Notice #{{ $decidingNotice->sequence_number }}</h4>

                            @if ($decidingNotice->requiresReviewBanner())
                                <div class="bg-danger/10 border border-danger/30 rounded-md p-3 text-sm text-charcoal">
                                    This is the tenant's {{ $decidingNotice->sequence_number }}th notice. Review their full history below before deciding — an extension request here is never automatically approved or rejected just because of the count.
                                </div>
                            @endif

                            <div>
                                <x-input-label for="decision" value="Decision" />
                                <select id="decision" wire:model.live="decision" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                    @if ($decidingNotice->tenant_response_type === 'request_time')
                                        <option value="approve_time">Approve — grant the requested extra time</option>
                                    @endif
                                    @if ($decidingNotice->tenant_response_type === 'request_extension')
                                        <option value="approve_extension">Approve — renew the tenancy agreement</option>
                                    @endif
                                    <option value="decline">Decline — the original notice stands</option>
                                </select>
                                <x-input-error :messages="$errors->get('decision')" class="mt-1" />
                            </div>

                            @if ($decision === 'approve_extension')
                                <div>
                                    <x-input-label for="decision_new_lease_end_date" value="New Agreement End Date" />
                                    <x-text-input id="decision_new_lease_end_date" type="date" wire:model="decision_new_lease_end_date" class="mt-1 block w-full max-w-xs" />
                                    <p class="text-xs text-brand-slate mt-1">A renewed agreement will be generated for the tenant to review.</p>
                                    <x-input-error :messages="$errors->get('decision_new_lease_end_date')" class="mt-1" />
                                </div>
                            @endif

                            <div>
                                <x-input-label for="decision_note" value="Note (optional)" />
                                <textarea id="decision_note" wire:model="decision_note" rows="2" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                                <x-input-error :messages="$errors->get('decision_note')" class="mt-1" />
                            </div>

                            @if ($decidingNotice->requiresReviewBanner())
                                <label class="flex items-start gap-2 text-sm text-charcoal">
                                    <input type="checkbox" wire:model="acknowledgeRepeatNoticeHistory" class="mt-0.5 rounded border-brand-border" />
                                    <span>I've reviewed this tenant's full notice history below before deciding.</span>
                                </label>
                                <x-input-error :messages="$errors->get('acknowledgeRepeatNoticeHistory')" class="mt-1" />
                            @endif

                            <div class="flex gap-3">
                                <x-primary-button wire:click="decideNotice">Confirm Decision</x-primary-button>
                                <x-secondary-button wire:click="cancelDecideNotice">Cancel</x-secondary-button>
                            </div>
                        </div>
                    @endif

                    @if ($noticeHistory->isNotEmpty())
                        <div class="pt-2 border-t border-brand-border">
                            <p class="text-xs font-semibold text-brand-slate uppercase tracking-wider mb-2">History ({{ $noticeCount }})</p>
                            <div class="space-y-2">
                                @foreach ($noticeHistory as $notice)
                                    <div class="flex items-start justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0">
                                        <div>
                                            <p class="text-charcoal">
                                                #{{ $notice->sequence_number }} — {{ ucfirst(str_replace('_', ' ', $notice->reason_category)) }}
                                                <span class="text-xs text-brand-slate">({{ $notice->notice_date->format('d M Y') }})</span>
                                            </p>
                                            @if ($notice->decision)
                                                <p class="text-xs text-brand-slate">Decision: {{ ucfirst(str_replace('_', ' ', $notice->decision)) }}{{ $notice->decision_note ? ' — '.$notice->decision_note : '' }}</p>
                                            @endif
                                            @if ($notice->withdrawn_at)
                                                <p class="text-xs text-brand-slate">Withdrawn{{ $notice->withdrawn_reason ? ': '.$notice->withdrawn_reason : '' }}</p>
                                            @endif
                                        </div>
                                        <x-status-badge :status="$notice->displayStatus()" />
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="space-y-4 border-t border-brand-border pt-4">
                    <h4 class="text-sm font-semibold text-charcoal">Change Requests</h4>

                    @if ($changeRequestHistory->isEmpty())
                        <p class="text-sm text-brand-slate">No change requests from this tenant yet.</p>
                    @else
                        <div class="space-y-2">
                            @foreach ($changeRequestHistory as $request)
                                <div class="flex items-start justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0">
                                    <div>
                                        <p class="text-charcoal">
                                            {{ ucfirst(str_replace('_', ' ', $request->category)) }}
                                            <span class="text-xs text-brand-slate">({{ $request->created_at->format('d M Y') }})</span>
                                        </p>
                                        <p class="text-xs text-brand-slate">{{ $request->requested_change }}</p>
                                        @if ($request->current_value)
                                            <p class="text-xs text-brand-slate">Current: {{ $request->current_value }}</p>
                                        @endif
                                        @if ($request->reason)
                                            <p class="text-xs text-brand-slate">Reason: {{ $request->reason }}</p>
                                        @endif
                                        @foreach ($request->documents as $document)
                                            <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="block text-xs text-teal hover:underline">{{ $document->original_filename }}</a>
                                        @endforeach
                                        @if ($request->decision_note)
                                            <p class="text-xs text-brand-slate mt-0.5">Decision note: {{ $request->decision_note }}</p>
                                        @endif
                                    </div>
                                    <div class="text-right shrink-0">
                                        <x-status-badge :status="$request->status" />
                                        @can('update', $tenancy)
                                            @if ($request->isOpen())
                                                <button wire:click="startDecideChangeRequest({{ $request->id }})" class="block mt-1 text-xs text-teal hover:underline">Decide</button>
                                            @endif
                                        @endcan
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($decidingChangeRequestId && $decidingChangeRequest)
                        <div class="border border-teal/30 bg-teal/5 rounded-md p-4 space-y-3">
                            <h4 class="text-sm font-semibold text-charcoal">Decide on Change Request</h4>
                            <div>
                                <x-input-label for="change_decision" value="Decision" />
                                <select id="change_decision" wire:model="change_decision" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                    <option value="accepted">Accept</option>
                                    <option value="rejected">Reject</option>
                                </select>
                            </div>
                            <div>
                                <x-input-label for="change_decision_note" value="Note (optional)" />
                                <textarea id="change_decision_note" wire:model="change_decision_note" rows="2" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                            </div>
                            <p class="text-xs text-brand-slate">Accepting does not change the tenancy by itself — make the actual change through the relevant tool, then document it.</p>
                            <div class="flex gap-3">
                                <x-primary-button wire:click="decideChangeRequest">Confirm Decision</x-primary-button>
                                <x-secondary-button wire:click="cancelDecideChangeRequest">Cancel</x-secondary-button>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="space-y-4 border-t border-brand-border pt-4">
                    <h4 class="text-sm font-semibold text-charcoal">Tenancy Renewal</h4>

                    @if (! $activeRenewalRequest && $renewalHistory->isEmpty())
                        <p class="text-sm text-brand-slate">No renewal requests from this tenant yet.</p>
                    @endif

                    @if ($activeRenewalRequest)
                        <div class="border border-brand-border rounded-md p-4 space-y-2">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm text-charcoal">
                                    Requested {{ $activeRenewalRequest->requested_duration_months }} month(s){{ $activeRenewalRequest->preferred_new_end_date ? ' — preferred end date '.$activeRenewalRequest->preferred_new_end_date->format('d M Y') : '' }}
                                </p>
                                <x-status-badge :status="$activeRenewalRequest->status" />
                            </div>
                            @if ($activeRenewalRequest->message)
                                <p class="text-sm text-brand-slate">{{ $activeRenewalRequest->message }}</p>
                            @endif

                            @can('update', $tenancy)
                                @if ($activeRenewalRequest->isOpen() && $activeRenewalRequest->status !== 'alternative_proposed')
                                    <button wire:click="startDecideRenewal({{ $activeRenewalRequest->id }})" class="text-sm text-teal hover:underline">Decide</button>
                                @endif
                            @endcan
                        </div>
                    @endif

                    @if ($decidingRenewalId && $decidingRenewal)
                        <div class="border border-teal/30 bg-teal/5 rounded-md p-4 space-y-3">
                            <h4 class="text-sm font-semibold text-charcoal">Decide on Renewal Request</h4>

                            @if (! $showProposeAlternative)
                                <div>
                                    <x-input-label for="renewal_decision" value="Decision" />
                                    <select id="renewal_decision" wire:model="renewal_decision" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                        <option value="approve">Approve — renew as requested</option>
                                        <option value="decline">Decline</option>
                                    </select>
                                </div>
                                <div>
                                    <x-input-label for="renewal_decision_note" value="Note (optional)" />
                                    <textarea id="renewal_decision_note" wire:model="renewal_decision_note" rows="2" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                                </div>
                                <div class="flex gap-3">
                                    <x-primary-button wire:click="decideRenewal">Confirm Decision</x-primary-button>
                                    <button type="button" wire:click="toggleProposeAlternative" class="text-sm text-brand-slate hover:text-charcoal">Propose Different Terms Instead</button>
                                    <x-secondary-button wire:click="cancelDecideRenewal">Cancel</x-secondary-button>
                                </div>
                            @else
                                <div>
                                    <x-input-label for="alt_duration_months" value="Alternative Duration (months)" />
                                    <x-text-input id="alt_duration_months" type="number" min="1" max="60" wire:model="alt_duration_months" class="mt-1 block w-full max-w-xs" />
                                    <x-input-error :messages="$errors->get('alt_duration_months')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="alt_new_end_date" value="Alternative New End Date (optional)" />
                                    <x-text-input id="alt_new_end_date" type="date" wire:model="alt_new_end_date" class="mt-1 block w-full max-w-xs" />
                                    <x-input-error :messages="$errors->get('alt_new_end_date')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="alt_note" value="Note to Tenant (optional)" />
                                    <textarea id="alt_note" wire:model="alt_note" rows="2" class="mt-1 block w-full rounded-md border-brand-border text-sm"></textarea>
                                </div>
                                <div class="flex gap-3">
                                    <x-primary-button wire:click="proposeAlternativeRenewalTerms">Send Alternative Terms</x-primary-button>
                                    <button type="button" wire:click="toggleProposeAlternative" class="text-sm text-brand-slate hover:text-charcoal">Back</button>
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
            </div>
        </details>

        <details id="details-section" class="scroll-mt-32 bg-white shadow-sm rounded-lg border border-brand-border open:shadow-md">
            <summary class="px-5 py-4 cursor-pointer select-none text-sm font-semibold text-charcoal">Tenancy Details</summary>

            <div class="px-5 pb-5 space-y-4">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <p class="text-brand-slate">Phone</p>
                        <p class="text-charcoal">{{ $tenancy->tenant->phone }}</p>
                    </div>
                    <div>
                        <p class="text-brand-slate">Move-in Date</p>
                        <p class="text-charcoal">{{ $tenancy->move_in_date->format('d M Y') }}</p>
                    </div>
                    <div>
                        <p class="text-brand-slate">Lease Expiry</p>
                        <p class="text-charcoal">{{ $tenancy->lease_end_date?->format('d M Y') ?? 'Open-ended' }}</p>
                    </div>
                    <div>
                        <p class="text-brand-slate">Status</p>
                        <x-status-badge :status="$tenancy->status" />
                    </div>
                    <div>
                        <p class="text-brand-slate">Rent Due Date</p>
                        <p class="text-charcoal">
                            {{ \App\Models\Tenancy::ordinalDay($tenancy->dueDay()) }}
                            @if ($tenancy->due_day_requested_by_tenant)
                                <span class="text-xs text-warning">(tenant-requested, no grace period)</span>
                            @endif
                        </p>
                    </div>
                    @if ($tenancy->notice_date)
                        <div>
                            <p class="text-brand-slate">Notice Given</p>
                            <p class="text-charcoal">{{ $tenancy->notice_date->format('d M Y') }}</p>
                        </div>
                    @endif
                    @if ($tenancy->move_out_date)
                        <div>
                            <p class="text-brand-slate">Move-out Date</p>
                            <p class="text-charcoal">{{ $tenancy->move_out_date->format('d M Y') }}</p>
                        </div>
                    @endif
                    @if ($tenancy->termination_reason)
                        <div class="col-span-2">
                            <p class="text-brand-slate">Termination Reason</p>
                            <p class="text-charcoal">{{ $tenancy->termination_reason }}</p>
                        </div>
                    @endif
                </div>

                @if ($dueDayChangeHistory->isNotEmpty())
                    <div>
                        <button wire:click="toggleDueDayHistory" class="text-xs text-brand-slate hover:underline">
                            {{ $showDueDayHistory ? 'Hide' : 'Show' }} due-date change history ({{ $dueDayChangeHistory->count() }})
                        </button>
                        @if ($showDueDayHistory)
                            <ul class="text-xs text-brand-slate space-y-1 mt-1">
                                @foreach ($dueDayChangeHistory as $past)
                                    <li>
                                        {{ $past->decided_at->format('d M Y') }} — requested day {{ $past->requested_due_day }},
                                        <span class="{{ $past->status === 'accepted' ? 'text-success' : 'text-danger' }}">{{ ucfirst($past->status) }}</span>
                                        by {{ $past->decidedBy?->name ?? 'landlord' }}
                                        @if ($past->decision_note)
                                            — "{{ $past->decision_note }}"
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                @if ($waiverRequestHistory->isNotEmpty())
                    <div>
                        <button wire:click="toggleWaiverHistory" class="text-xs text-brand-slate hover:underline">
                            {{ $showWaiverHistory ? 'Hide' : 'Show' }} late-fee waiver history ({{ $waiverRequestHistory->count() }})
                        </button>
                        @if ($showWaiverHistory)
                            <ul class="text-xs text-brand-slate space-y-1 mt-1">
                                @foreach ($waiverRequestHistory as $past)
                                    <li>
                                        {{ $past->decided_at->format('d M Y') }} —
                                        {{ $past->rentCharge ? \Carbon\Carbon::create()->month($past->rentCharge->period_month)->format('F').' '.$past->rentCharge->period_year : 'charge' }},
                                        KSh {{ number_format($past->requested_amount, 2) }}
                                        <span class="{{ $past->status === 'accepted' ? 'text-success' : 'text-danger' }}">{{ ucfirst($past->status) }}</span>
                                        by {{ $past->decidedBy?->name ?? 'landlord' }}
                                        @if ($past->decision_note)
                                            — "{{ $past->decision_note }}"
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
        </details>
    </div>
</div>
