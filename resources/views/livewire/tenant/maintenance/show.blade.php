    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Maintenance Request</h2>
                <a href="{{ route('tenant.maintenance.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">&larr; Back to My Requests</a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                @php
                    $task = $complaint->maintenanceTask;
                    $rawStatus = $task->status ?? $complaint->status;
                    $displayStatus = \App\Livewire\Tenant\Maintenance\Index::STATUS_MAP[$rawStatus] ?? $rawStatus;
                @endphp

                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-charcoal">{{ $complaint->subject }}</p>
                            <p class="text-xs text-brand-slate mt-0.5">
                                {{ ucfirst($complaint->category) }} · {{ $complaint->house->house_number ?? '' }}
                            </p>
                        </div>
                        <x-status-badge :status="$displayStatus" />
                    </div>

                    @if ($complaint->relatedComplaint)
                        <p class="text-xs text-brand-slate mt-2">
                            Reported as a recurrence of
                            <a href="{{ route('tenant.maintenance.show', $complaint->relatedComplaint) }}" wire:navigate class="text-teal hover:underline">{{ $complaint->relatedComplaint->subject }}</a>.
                        </p>
                    @endif

                    <p class="text-sm text-brand-slate mt-3">{{ $complaint->description }}</p>

                    @if ($complaint->documents->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($complaint->documents as $document)
                                <a href="{{ $document->url() }}" target="_blank" class="text-xs text-teal hover:underline">{{ $document->original_filename }}</a>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-brand-slate mt-3">
                        <span>Submitted: {{ $complaint->created_at->format('d M Y H:i') }}</span>
                        @if ($task?->scheduled_date)
                            <span>Scheduled: {{ $task->scheduled_date->format('d M Y') }}</span>
                        @endif
                        @if ($complaint->resolved_at)
                            <span>Resolved: {{ $complaint->resolved_at->format('d M Y') }}</span>
                        @endif
                        @if ($complaint->tenant_confirmed_at)
                            <span class="text-success">Confirmed by you: {{ $complaint->tenant_confirmed_at->format('d M Y') }}</span>
                        @endif
                    </div>
                </div>

                @if ($complaint->isClosed())
                    <div class="bg-offwhite border border-brand-border rounded-lg p-5 space-y-3">
                        <p class="text-sm font-semibold text-charcoal">This request is closed</p>
                        <p class="text-sm text-brand-slate">You confirmed this was fixed, so the conversation is closed. If the issue has come back, let your landlord know below.</p>

                        @if (! $showRecurringForm)
                            <button wire:click="openRecurringForm" class="text-sm text-charcoal border border-brand-border rounded-md px-3 py-1.5 hover:bg-white">Report a Recurring Issue</button>
                        @else
                            <form wire:submit="reportRecurringIssue" class="space-y-2">
                                <textarea wire:model="recurringNote" rows="3" class="block w-full rounded-md border-brand-border text-sm" placeholder="What's happening now?"></textarea>
                                <x-input-error :messages="$errors->get('recurringNote')" class="mt-1" />
                                <div class="flex items-center gap-3">
                                    <x-primary-button type="submit">Report It</x-primary-button>
                                    <button type="button" wire:click="cancelRecurringForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                </div>
                            </form>
                        @endif

                        @if ($complaint->followUps->isNotEmpty())
                            <div class="pt-2 border-t border-brand-border">
                                <p class="text-xs font-medium text-brand-slate mb-1">Reported again as:</p>
                                @foreach ($complaint->followUps as $followUp)
                                    <a href="{{ route('tenant.maintenance.show', $followUp) }}" wire:navigate class="block text-xs text-teal hover:underline">{{ $followUp->subject }}</a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if ($complaint->awaitingTenantConfirmation())
                    <div class="bg-warning/5 border border-warning/30 rounded-lg p-5 space-y-3">
                        <p class="text-sm font-semibold text-charcoal">Was this actually fixed?</p>
                        <p class="text-sm text-brand-slate">Your landlord marked this request resolved. Let them know whether the work was done.</p>

                        @if (! $showReopenForm)
                            <div class="flex items-center gap-3">
                                <button wire:click="confirmResolved" class="text-sm text-white bg-success rounded-md px-3 py-1.5 hover:opacity-90">Yes, it's fixed</button>
                                <button wire:click="openReopenForm" class="text-sm text-charcoal border border-brand-border rounded-md px-3 py-1.5 hover:bg-offwhite">No, still a problem</button>
                            </div>
                        @else
                            <form wire:submit="reopenComplaint" class="space-y-2">
                                <textarea wire:model="reopenReason" rows="3" class="block w-full rounded-md border-brand-border text-sm" placeholder="What's still wrong?"></textarea>
                                <x-input-error :messages="$errors->get('reopenReason')" class="mt-1" />
                                <div class="flex items-center gap-3">
                                    <x-primary-button type="submit">Send &amp; Reopen</x-primary-button>
                                    <button type="button" wire:click="cancelReopenForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endif

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="px-5 py-3 border-b border-brand-border">
                        <h3 class="text-sm font-semibold text-charcoal">Updates</h3>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @forelse ($updates as $update)
                            @php $isMe = $update->user_id === auth()->id(); @endphp
                            <li class="px-5 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-medium {{ $isMe ? 'text-teal' : 'text-navy' }}">
                                        {{ $isMe ? 'You' : ($update->user?->name ?? 'Landlord') }}
                                    </span>
                                    <span class="text-xs text-gray-400">{{ $update->created_at->format('d M Y H:i') }}</span>
                                </div>
                                @if ($update->note)
                                    <p class="text-sm text-charcoal mt-1">{{ $update->note }}</p>
                                @endif
                                @if ($update->status_from !== $update->status_to)
                                    <p class="text-xs text-brand-slate mt-1">Status changed: {{ $update->status_from }} &rarr; {{ $update->status_to }}</p>
                                @endif
                            </li>
                        @empty
                            <li class="px-5 py-6 text-center text-gray-400 text-sm">No updates yet.</li>
                        @endforelse
                    </ul>
                </div>

                @if (! $complaint->isClosed())
                    <form wire:submit="reply" class="bg-white shadow-sm rounded-lg border border-brand-border p-5 space-y-3">
                        <x-input-label for="replyNote" value="Add a reply" />
                        <textarea id="replyNote" wire:model="replyNote" rows="3" class="block w-full rounded-md border-brand-border text-sm" placeholder="Ask a question or add more detail…"></textarea>
                        <x-input-error :messages="$errors->get('replyNote')" class="mt-1" />
                        <x-primary-button type="submit">Send Reply</x-primary-button>
                    </form>
                @endif
            </div>
        </div>
    </div>
