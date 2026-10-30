    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Contract</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
                @if (session('status'))
                    <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div x-data x-init="$el.scrollIntoView({behavior: 'smooth', block: 'center'})" class="bg-danger/10 border border-danger/30 text-danger text-sm rounded-md px-4 py-2">{{ session('error') }}</div>
                @endif

                @if (! $tenancy)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        No active tenancy is linked to your account yet.
                    </div>
                @elseif (! $contract)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        Your landlord hasn't generated your tenancy agreement yet.
                    </div>
                @else
                    @if ($contract->isAwaitingSignature())
                        <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold text-charcoal mb-1">Tenancy Agreement — Awaiting Your Signature</h3>
                                <p class="text-xs text-brand-slate">Please review the agreement below before signing.</p>
                            </div>

                            @if ($contract->body_snapshot)
                                <div class="border border-brand-border rounded-md p-4 max-h-80 overflow-y-auto text-sm text-charcoal whitespace-pre-wrap bg-offwhite">{{ $contract->body_snapshot }}</div>
                            @elseif ($contract->document)
                                <embed src="{{ $contract->document->url() }}" type="application/pdf" class="w-full h-96 rounded-md border border-brand-border" />
                                <a href="{{ $contract->document->url() }}" target="_blank" rel="noopener" class="text-teal hover:underline text-xs font-medium">Open in a new tab</a>
                            @endif

                            <form wire:submit="sign" class="space-y-4 border-t border-brand-border pt-4">
                                @if ($hasIdDocument)
                                    <p class="text-xs text-success flex items-center gap-1"><x-nav-icon name="check" class="h-3.5 w-3.5 shrink-0" /> Your ID/passport is already on file.</p>
                                @else
                                    <div>
                                        <x-input-label for="idDocument" value="Upload a copy of your ID or Passport" />
                                        <input type="file" id="idDocument" wire:model="idDocument" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm" />
                                        <div wire:loading wire:target="idDocument" class="text-xs text-brand-slate mt-1">Uploading…</div>
                                        <p class="text-xs text-brand-slate mt-1">Required before signing — your landlord needs this on file for your tenancy agreement.</p>
                                        <x-input-error :messages="$errors->get('idDocument')" class="mt-1" />
                                    </div>
                                @endif

                                <label class="flex items-start gap-2 text-sm text-charcoal">
                                    <input type="checkbox" wire:model="agreedToTerms" class="mt-0.5 rounded border-brand-border" />
                                    I have read, understood and agree to the terms of this tenancy agreement.
                                </label>
                                <x-input-error :messages="$errors->get('agreedToTerms')" class="mt-1" />

                                <div>
                                    <x-input-label for="typedFullName" value="Type your full legal name to sign" />
                                    <x-text-input id="typedFullName" type="text" wire:model="typedFullName" class="mt-1 block w-full" placeholder="e.g. Anne Murangiri" />
                                    <x-input-error :messages="$errors->get('typedFullName')" class="mt-1" />
                                </div>

                                <x-primary-button type="submit" wire:confirm="This will be recorded as your legal signature on this tenancy agreement. Continue?">Confirm &amp; Sign Contract</x-primary-button>
                            </form>
                        </div>
                    @elseif ($contract->isSigned())
                        <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6">
                            <div class="flex items-center gap-2 mb-3">
                                <h3 class="text-sm font-semibold text-charcoal">Tenancy Agreement</h3>
                                <x-status-badge status="signed" />
                            </div>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p class="text-brand-slate">Signed By</p>
                                    <p class="text-charcoal">{{ $contract->signed_by_name }}</p>
                                </div>
                                <div>
                                    <p class="text-brand-slate">Date Signed</p>
                                    <p class="text-charcoal">{{ $contract->signed_at->format('d M Y H:i') }}</p>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                            Your tenancy agreement is being prepared. You'll be notified once it's ready to sign.
                        </div>
                    @endif

                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-3">
                        <h3 class="text-sm font-semibold text-charcoal">Rent Due Date</h3>

                        <p class="text-sm text-brand-slate">
                            Your rent is currently due on the <strong>{{ \App\Models\Tenancy::ordinalDay($tenancy->dueDay()) }}</strong> of each month.
                            @if ($tenancy->due_day_requested_by_tenant)
                                This is a date you requested — it has <strong>no grace period</strong>: a late fee applies the day after if unpaid.
                            @else
                                This is the standard due date, which comes with a grace period before any late fee applies.
                            @endif
                        </p>

                        @php $pendingDueDayRequest = $tenancy->pendingDueDayChangeRequest(); @endphp
                        @if ($pendingDueDayRequest)
                            <div class="flex items-center gap-2">
                                <x-status-badge status="pending" />
                                <span class="text-xs text-brand-slate">Request to move to day {{ $pendingDueDayRequest->requested_due_day }} is awaiting your landlord's decision.</span>
                            </div>
                        @else
                            @php $latestDecidedDueDayRequest = $tenancy->latestDecidedDueDayChangeRequest(); @endphp
                            @if ($latestDecidedDueDayRequest?->status === 'rejected')
                                <p class="text-xs text-danger">Your last request to change this date was declined.</p>
                                @if ($latestDecidedDueDayRequest->decision_note)
                                    <p class="text-xs text-brand-slate">"{{ $latestDecidedDueDayRequest->decision_note }}"</p>
                                @endif
                            @endif
                            <button wire:click="$set('showDueDayForm', true)" class="text-xs text-teal hover:underline">Request a different due date</button>
                        @endif

                        @if ($dueDayHistory->isNotEmpty())
                            <button wire:click="toggleDueDayHistory" class="block text-xs text-brand-slate hover:underline">
                                {{ $showDueDayHistory ? 'Hide' : 'Show' }} past requests ({{ $dueDayHistory->count() }})
                            </button>
                            @if ($showDueDayHistory)
                                <ul class="text-xs text-brand-slate space-y-1 border-t border-gray-100 pt-2">
                                    @foreach ($dueDayHistory as $past)
                                        <li>
                                            {{ $past->decided_at->format('d M Y') }} — requested day {{ $past->requested_due_day }},
                                            <span class="{{ $past->status === 'accepted' ? 'text-success' : 'text-danger' }}">{{ ucfirst($past->status) }}</span>
                                            @if ($past->decision_note)
                                                — "{{ $past->decision_note }}"
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    </div>

                    @if ($documents->isNotEmpty())
                        <div class="bg-white shadow-sm rounded-lg border border-brand-border divide-y divide-gray-100">
                            @foreach ($documents as $document)
                                <div class="px-5 py-4 flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-charcoal">
                                            {{ $document->original_filename }}
                                            @if ($loop->first)
                                                <span class="ml-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-green-50 text-green-700">Current</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-brand-slate">Uploaded {{ $document->created_at->format('d M Y') }}</p>
                                    </div>
                                    <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="text-teal hover:underline text-xs font-medium">
                                        View / Download
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        </div>

        @if ($showDueDayForm)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="cancelDueDayForm">
                <form wire:submit="requestDueDayChange" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-md space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">Request a Different Due Date</h3>

                    <div class="bg-warning/10 border border-warning/30 rounded-md p-3 text-xs text-charcoal">
                        <strong>Please note:</strong> everyone on the standard due date gets a grace period before a late fee applies.
                        If you request your own date instead, that grace period no longer applies — a late fee is charged immediately
                        the day after your chosen date if rent is still unpaid.
                    </div>

                    <div>
                        <x-input-label for="requestedDueDay" value="Day of the month (1–31)" />
                        <x-text-input id="requestedDueDay" type="number" min="1" max="31" wire:model="requestedDueDay" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('requestedDueDay')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="dueDayReason" value="Reason (optional)" />
                        <textarea id="dueDayReason" wire:model="dueDayReason" rows="3" class="mt-1 block w-full rounded-md border-brand-border" placeholder="e.g. I get paid on the 25th"></textarea>
                        <x-input-error :messages="$errors->get('dueDayReason')" class="mt-1" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Send Request</x-primary-button>
                        <button type="button" wire:click="cancelDueDayForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
