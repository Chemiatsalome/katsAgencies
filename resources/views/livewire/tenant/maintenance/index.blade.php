    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">My Requests</h2>
                <a href="{{ route('tenant.maintenance.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                    + New Request
                </a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                <div class="space-y-3">
                    @forelse ($complaints as $complaint)
                        @php
                            $task = $complaint->maintenanceTask;
                            $rawStatus = $task->status ?? $complaint->status;
                            $displayStatus = \App\Livewire\Tenant\Maintenance\Index::STATUS_MAP[$rawStatus] ?? $rawStatus;
                        @endphp
                        <div wire:key="complaint-{{ $complaint->id }}" class="bg-white shadow-sm rounded-lg border border-brand-border p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-charcoal">{{ $complaint->subject }}</p>
                                    <p class="text-xs text-brand-slate mt-0.5">
                                        {{ ucfirst($complaint->category) }} · {{ $complaint->house->house_number ?? '' }}
                                    </p>
                                </div>
                                <x-status-badge :status="$displayStatus" />
                            </div>

                            <p class="text-sm text-brand-slate mt-2">{{ $complaint->description }}</p>

                            <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-brand-slate mt-3">
                                <span>Submitted: {{ $complaint->created_at->format('d M Y') }}</span>
                                @if ($task?->scheduled_date)
                                    <span>Scheduled: {{ $task->scheduled_date->format('d M Y') }}</span>
                                @endif
                                @if ($complaint->resolved_at)
                                    <span>Resolved: {{ $complaint->resolved_at->format('d M Y') }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-center text-gray-400">
                            You haven't submitted any maintenance requests yet.
                        </div>
                    @endforelse
                </div>

                <x-per-page-select :paginator="$complaints" />
            </div>
        </div>
    </div>
