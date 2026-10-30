    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Maintenance</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif

            {{-- Not in the header slot above — Livewire's reactive wire:id
                 boundary only wraps the layout's main $slot, not $header, so a
                 wire:click placed there silently never fires. --}}
            @if ($tab === 'scheduled' || $tab === 'vendors')
                <div class="flex justify-end">
                    @if ($tab === 'scheduled')
                        <button wire:click="openTaskForm" class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">+ New Task</button>
                    @else
                        <button wire:click="openVendorForm" class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">+ New Vendor</button>
                    @endif
                </div>
            @endif

            <div class="flex gap-2 flex-wrap border-b border-brand-border">
                @foreach (['dashboard' => 'Dashboard', 'requests' => 'Requests', 'scheduled' => 'Work Orders', 'calendar' => 'Calendar', 'history' => 'History', 'vendors' => 'Vendors'] as $value => $label)
                    <button wire:click="$set('tab', '{{ $value }}')" class="px-3 py-2 text-sm border-b-2 {{ $tab === $value ? 'border-navy text-navy font-medium' : 'border-transparent text-brand-slate hover:text-charcoal' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- DASHBOARD --}}
            @if ($tab === 'dashboard')
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <x-stat-tile label="Open Tasks" :value="$openCount" href="{{ route('manage.maintenance.index', ['tab' => 'scheduled', 'status' => 'open']) }}" />
                    <x-stat-tile label="Emergencies" :value="$emergencyCount" tone="{{ $emergencyCount > 0 ? 'critical' : 'neutral' }}" />
                    <x-stat-tile label="Awaiting Tenant Confirmation" :value="$awaitingConfirmationCount" href="{{ route('manage.maintenance.index', ['tab' => 'scheduled', 'status' => 'completed']) }}" tone="{{ $awaitingConfirmationCount > 0 ? 'warning' : 'neutral' }}" />
                    <x-stat-tile label="Scheduled Today" :value="$scheduledTodayCount" />
                    <x-stat-tile label="Preventive Tasks Due" :value="$overdueScheduleCount" href="{{ route('manage.maintenance.schedules.index') }}" tone="{{ $overdueScheduleCount > 0 ? 'warning' : 'neutral' }}" />
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="px-5 py-4 border-b border-brand-border">
                        <h3 class="text-sm font-semibold text-charcoal">Needs Attention</h3>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Task</th>
                                <th class="text-left px-5 py-2 font-medium">Property</th>
                                <th class="text-left px-5 py-2 font-medium">Priority</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($recentOpenTasks as $item)
                                <tr class="hover:bg-gray-50 cursor-pointer" wire:click="$set('tab', '{{ $item->type === 'complaint' ? 'requests' : 'scheduled' }}')">
                                    <td class="px-5 py-2.5 font-medium text-charcoal">
                                        {{ $item->label }}
                                        @if ($item->type === 'complaint')
                                            <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-navy-light text-navy">New Request</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $item->propertyLabel }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$item->priority" /></td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$item->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No open maintenance tasks.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @endif

            {{-- REQUESTS (from tenant complaints) --}}
            @if ($tab === 'requests')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Subject</th>
                                <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                <th class="text-left px-5 py-2 font-medium">Property</th>
                                <th class="text-left px-5 py-2 font-medium">Priority</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="text-right px-5 py-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($complaints as $complaint)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-2.5 font-medium text-charcoal">{{ $complaint->subject }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $complaint->tenant?->name ?? '—' }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $complaint->property?->name ?? $complaint->house?->block->property->name ?? '—' }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$complaint->priority" /></td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$complaint->status" /></td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap space-x-3">
                                        <button wire:click="openComplaintThread({{ $complaint->id }})" class="text-xs text-brand-slate hover:underline">
                                            Thread{{ $complaint->updates_count > 0 ? ' ('.$complaint->updates_count.')' : '' }}
                                        </button>
                                        @if ($complaint->maintenanceTask)
                                            <button wire:click="$set('tab', 'scheduled')" class="text-xs text-brand-slate hover:underline">
                                                Already Converted — View Work Order
                                            </button>
                                        @else
                                            <button
                                                wire:click="convertToTask({{ $complaint->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="convertToTask({{ $complaint->id }})"
                                                class="text-xs text-teal hover:underline disabled:opacity-50 disabled:cursor-not-allowed"
                                            >Convert to Task</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">No open tenant requests.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @endif

            {{-- HISTORY (resolved/closed tenant requests, plus standalone completed/closed tasks with no tenant request behind them) --}}
            @if ($tab === 'history')
                <div class="space-y-2">
                    <h3 class="text-sm font-semibold text-charcoal">Resolved Tenant Requests</h3>
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                        <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Subject</th>
                                    <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                    <th class="text-left px-5 py-2 font-medium">Property</th>
                                    <th class="text-left px-5 py-2 font-medium">Tenant Confirmed</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                    <th class="text-right px-5 py-2 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($historyComplaints as $complaint)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-5 py-2.5 font-medium text-charcoal">
                                            {{ $complaint->subject }}
                                            @if ($complaint->relatedComplaint)
                                                <span class="block text-[11px] text-brand-slate font-normal">Recurrence of a previous request</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2.5 text-brand-slate">{{ $complaint->tenant?->name ?? '—' }}</td>
                                        <td class="px-5 py-2.5 text-brand-slate">{{ $complaint->property?->name ?? $complaint->house?->block->property->name ?? '—' }}</td>
                                        <td class="px-5 py-2.5 text-brand-slate">
                                            {{ $complaint->tenant_confirmed_at?->format('d M Y') ?? 'Awaiting confirmation' }}
                                        </td>
                                        <td class="px-5 py-2.5"><x-status-badge :status="$complaint->status" /></td>
                                        <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                            <button wire:click="openComplaintThread({{ $complaint->id }})" class="text-xs text-brand-slate hover:underline">
                                                View Thread{{ $complaint->updates_count > 0 ? ' ('.$complaint->updates_count.')' : '' }}
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">No resolved requests yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <x-per-page-select :paginator="$historyComplaints" />
                </div>

                <div class="space-y-2">
                    <h3 class="text-sm font-semibold text-charcoal">Completed Tasks (No Tenant Request)</h3>
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                        <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Task</th>
                                    <th class="text-left px-5 py-2 font-medium">Property</th>
                                    <th class="text-left px-5 py-2 font-medium">Completed</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($historyTasks as $task)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-5 py-2.5 font-medium text-charcoal">{{ $task->title }}</td>
                                        <td class="px-5 py-2.5 text-brand-slate">{{ $task->property->name }}{{ $task->house ? ' — '.$task->house->house_number : '' }}</td>
                                        <td class="px-5 py-2.5 text-brand-slate">{{ $task->completed_at?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-5 py-2.5"><x-status-badge :status="$task->status" /></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No completed standalone tasks yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <x-per-page-select :paginator="$historyTasks" />
                </div>
            @endif

            {{-- SCHEDULED (work orders / full status workflow) --}}
            @if ($tab === 'scheduled')
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label class="block text-xs font-medium text-brand-slate mb-1">Status</label>
                        <select wire:model.live="status" class="rounded-md border-brand-border text-sm">
                            <option value="open">Open</option>
                            <option value="all">All</option>
                            <option value="submitted">Submitted</option>
                            <option value="reviewed">Reviewed</option>
                            <option value="assigned">Assigned</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-brand-slate mb-1">Property</label>
                        <select wire:model.live="propertyId" class="rounded-md border-brand-border text-sm">
                            <option value="all">All Properties</option>
                            @foreach ($properties as $property)
                                <option value="{{ $property->id }}">{{ $property->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Task</th>
                                <th class="text-left px-5 py-2 font-medium">Property</th>
                                <th class="text-left px-5 py-2 font-medium">Vendor</th>
                                <th class="text-left px-5 py-2 font-medium">Scheduled</th>
                                <th class="text-left px-5 py-2 font-medium">Priority</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="text-right px-5 py-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($tasks as $task)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-2.5 font-medium text-charcoal">
                                        {{ $task->title }}
                                        @if ($task->deposit_deduction_id)
                                            <span class="block text-[11px] text-teal font-normal">Deducted from tenant deposit</span>
                                        @elseif ($task->status === 'completed' && $task->complaint_id && ! $task->complaint->tenant_confirmed_at)
                                            <span class="block text-[11px] text-warning font-normal">Awaiting tenant confirmation</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $task->property->name }}{{ $task->house ? ' — '.$task->house->house_number : '' }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $task->vendor?->name ?? '—' }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $task->scheduled_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$task->priority" /></td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$task->status" /></td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap space-x-2">
                                        @if ($task->status === 'submitted')
                                            <button wire:click="markReviewed({{ $task->id }})" class="text-xs text-teal hover:underline">Review</button>
                                        @endif
                                        @if (in_array($task->status, ['reviewed', 'assigned', 'scheduled']))
                                            <button wire:click="openAssignForm({{ $task->id }})" class="text-xs text-teal hover:underline">Assign / Schedule</button>
                                        @endif
                                        @if (in_array($task->status, ['assigned', 'scheduled']))
                                            <button wire:click="startProgress({{ $task->id }})" class="text-xs text-teal hover:underline">Start</button>
                                        @endif
                                        @if ($task->status === 'in_progress')
                                            <button wire:click="openCompleteForm({{ $task->id }})" class="text-xs text-success hover:underline">Complete</button>
                                        @endif
                                        @if ($task->status === 'completed')
                                            @php $taskDecision = $decisionsByTaskId[$task->id] ?? null; @endphp
                                            @if (! $task->deposit_deduction_id && ! $taskDecision && $task->actual_cost > 0)
                                                <button wire:click="openLiabilityDecision({{ $task->id }})" class="text-xs text-teal hover:underline">Decide Liability</button>
                                            @elseif ($costService->canDeductFromDeposit($task))
                                                <button wire:click="openDeductForm({{ $task->id }})" class="text-xs text-teal hover:underline">Deduct from Deposit</button>
                                            @endif
                                            <button wire:click="closeTask({{ $task->id }})" class="text-xs text-brand-slate hover:underline">Close</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-5 py-6 text-center text-gray-400">No tasks match this filter.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>

                <x-per-page-select :paginator="$tasks" />
            @endif

            {{-- CALENDAR (agenda list) --}}
            @if ($tab === 'calendar')
                <div class="space-y-4">
                    @forelse ($agenda as $date => $tasksOnDate)
                        <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                            <div class="px-5 py-3 border-b border-brand-border bg-gray-50">
                                <h3 class="text-sm font-semibold text-charcoal">{{ \Illuminate\Support\Carbon::parse($date)->format('l, d M Y') }}</h3>
                            </div>
                            <ul class="divide-y divide-gray-100">
                                @foreach ($tasksOnDate as $task)
                                    <li class="px-5 py-3 flex items-center justify-between text-sm">
                                        <div>
                                            <p class="font-medium text-charcoal">{{ $task->title }}</p>
                                            <p class="text-brand-slate">{{ $task->property->name }}{{ $task->house ? ' — '.$task->house->house_number : '' }} @if($task->scheduled_time) · {{ $task->scheduled_time->format('H:i') }} @endif @if($task->vendor) · {{ $task->vendor->name }} @endif</p>
                                        </div>
                                        <x-status-badge :status="$task->status" />
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-center text-gray-400">Nothing scheduled in the next 30 days.</div>
                    @endforelse
                </div>
            @endif

            {{-- VENDORS --}}
            @if ($tab === 'vendors')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Name</th>
                                <th class="text-left px-5 py-2 font-medium">Category</th>
                                <th class="text-left px-5 py-2 font-medium">Phone</th>
                                <th class="text-left px-5 py-2 font-medium">Rate</th>
                                <th class="text-right px-5 py-2 font-medium">Tasks</th>
                                <th class="text-right px-5 py-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($vendors as $vendor)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-2.5 font-medium text-charcoal">{{ $vendor->name }}{{ $vendor->company ? ' ('.$vendor->company.')' : '' }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $vendor->category }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $vendor->phone }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $vendor->rate ?: '—' }}</td>
                                    <td class="px-5 py-2.5 text-right text-brand-slate">{{ $vendor->maintenance_tasks_count }}</td>
                                    <td class="px-5 py-2.5 text-right">
                                        <button wire:click="deleteVendor({{ $vendor->id }})" wire:confirm="Delete this vendor?" class="text-xs text-danger hover:underline">Delete</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">No vendors added yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- New Task modal --}}
        @if ($showTaskForm)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="$set('showTaskForm', false)">
                <form wire:submit="createTask" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-lg space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">New Maintenance Task</h3>
                    <div>
                        <x-input-label for="task_property_id" value="Property" />
                        <select id="task_property_id" wire:model.live="task_property_id" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="">Select property…</option>
                            @foreach ($properties as $property)
                                <option value="{{ $property->id }}">{{ $property->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('task_property_id')" class="mt-1" />
                    </div>
                    @if ($task_property_id)
                        <div>
                            <x-input-label for="task_house_id" value="Unit (optional)" />
                            <select id="task_house_id" wire:model="task_house_id" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="">Whole property</option>
                                @foreach ($houses as $house)
                                    <option value="{{ $house->id }}">{{ $house->house_number }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <x-input-label for="task_title" value="Title" />
                        <x-text-input id="task_title" type="text" wire:model="task_title" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('task_title')" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="task_category" value="Category" />
                            <x-text-input id="task_category" type="text" wire:model="task_category" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="task_priority" value="Priority" />
                            <select id="task_priority" wire:model="task_priority" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="emergency">Emergency</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <x-input-label for="task_description" value="Description (optional)" />
                        <textarea id="task_description" wire:model="task_description" rows="3" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Task</x-primary-button>
                        <button type="button" wire:click="$set('showTaskForm', false)" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- Assign / Schedule modal --}}
        @if ($showAssignForm)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="$set('showAssignForm', false)">
                <form wire:submit="saveAssign" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-md space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">Assign / Schedule Task</h3>
                    <div>
                        <x-input-label for="assign_vendor_id" value="Vendor (optional)" />
                        @if ($vendors->isEmpty())
                            <p class="text-xs text-brand-slate mt-1">
                                No vendors yet — you can still schedule without one, or
                                <button type="button" wire:click="$set('showAssignForm', false); $set('tab', 'vendors'); $set('showVendorForm', true)" class="text-teal hover:underline">add one now</button>.
                            </p>
                        @else
                            <select id="assign_vendor_id" wire:model="assign_vendor_id" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="">No vendor</option>
                                @foreach ($vendors as $vendor)
                                    <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="assign_scheduled_date" value="Scheduled Date" />
                            <x-text-input id="assign_scheduled_date" type="date" wire:model="assign_scheduled_date" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="assign_scheduled_time" value="Time (optional)" />
                            <x-text-input id="assign_scheduled_time" type="time" wire:model="assign_scheduled_time" class="mt-1 block w-full" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="assign_estimated_cost" value="Cost (optional)" />
                        <x-text-input id="assign_estimated_cost" type="number" step="0.01" wire:model="assign_estimated_cost" class="mt-1 block w-full" placeholder="e.g. a deposit already paid to the vendor" />
                        <x-input-error :messages="$errors->get('assign_estimated_cost')" class="mt-1" />
                        <label class="flex items-start gap-2 text-xs text-brand-slate mt-2">
                            <input type="checkbox" wire:model="assign_record_expense_now" class="mt-0.5 rounded border-brand-border" />
                            <span>Record this as a property expense now, instead of waiting until the task is marked complete.</span>
                        </label>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save</x-primary-button>
                        <button type="button" wire:click="$set('showAssignForm', false)" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- Complete modal --}}
        @if ($showCompleteForm)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="$set('showCompleteForm', false)">
                <form wire:submit="complete" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-sm space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">Mark Task Complete</h3>
                    @if ($completingTaskHasExpense)
                        <div>
                            <p class="text-sm text-brand-slate mb-2">Already recorded: KSh {{ number_format($completingTaskPreviousCost, 2) }}</p>
                            <x-input-label for="complete_additional_cost" value="Additional Cost, if any (KSh)" />
                            <x-text-input id="complete_additional_cost" type="number" step="0.01" wire:model="complete_additional_cost" class="mt-1 block w-full" placeholder="e.g. a second visit cost more" />
                            <x-input-error :messages="$errors->get('complete_additional_cost')" class="mt-1" />
                            <p class="text-xs text-brand-slate mt-1">Only fill this in if getting it actually fixed cost more than the first time — it's recorded as a separate expense, on top of what's already on record.</p>
                        </div>
                    @else
                        <div>
                            <x-input-label for="complete_actual_cost" value="Actual Cost (KSh)" />
                            <x-text-input id="complete_actual_cost" type="number" step="0.01" wire:model="complete_actual_cost" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('complete_actual_cost')" class="mt-1" />
                            <p class="text-xs text-brand-slate mt-1">This will be recorded automatically as a property expense.</p>
                        </div>

                        @if ($completingTaskTenancy)
                            <div class="border-t border-brand-border pt-3">
                                <label class="flex items-start gap-2 text-sm text-charcoal">
                                    <input type="checkbox" wire:model="complete_deduct_from_deposit" class="mt-0.5 rounded border-brand-border">
                                    <span>Tenant is responsible, deduct this cost from {{ $completingTaskTenancy->tenant->name }}'s security deposit.</span>
                                </label>
                                @if ($complete_deduct_from_deposit)
                                    <p class="text-xs text-brand-slate mt-2">
                                        Currently held: KSh {{ number_format($completingTaskTenancy->depositHeldBalance(), 2) }}.
                                        Only use this when the damage is genuinely the tenant's responsibility, not normal wear and tear.
                                    </p>
                                    <div class="mt-2">
                                        <x-input-label for="complete_deduction_evidence" value="Supporting Evidence (optional, PDF/JPG/PNG)" />
                                        <input type="file" id="complete_deduction_evidence" wire:model="complete_deduction_evidence" class="mt-1 block w-full text-sm">
                                        <x-input-error :messages="$errors->get('complete_deduction_evidence')" class="mt-1" />
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endif
                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Mark Complete</x-primary-button>
                        <button type="button" wire:click="$set('showCompleteForm', false)" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- Deduct from Deposit modal (post-completion, tenant-confirmed) --}}
        @if ($deductingTaskId && $deductingTask)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="cancelDeductForm">
                <form wire:submit="confirmDeductFromDeposit" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-sm space-y-3">
                    <h3 class="text-sm font-semibold text-charcoal">Deduct from Tenant's Deposit</h3>
                    <p class="text-xs text-brand-slate">
                        {{ $deductingTask->title }}, KSh {{ number_format($deductingTask->actual_cost, 2) }}.
                        The tenant confirmed this fix on {{ $deductingTask->complaint->tenant_confirmed_at->format('d M Y') }}.
                    </p>
                    <div>
                        <x-input-label for="deductingTaskEvidence" value="Supporting Evidence (optional, PDF/JPG/PNG)" />
                        <input type="file" id="deductingTaskEvidence" wire:model="deductingTaskEvidence" class="mt-1 block w-full text-sm">
                        <x-input-error :messages="$errors->get('deductingTaskEvidence')" class="mt-1" />
                    </div>
                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Confirm Deduction</x-primary-button>
                        <button type="button" wire:click="cancelDeductForm" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($decidingTaskId && $decidingTask)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="cancelLiabilityDecision">
                <div class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-md space-y-3">
                    <h3 class="text-sm font-semibold text-charcoal">Who's liable for this maintenance cost?</h3>
                    <p class="text-xs text-brand-slate">{{ $decidingTask->title }} — KSh {{ number_format($decidingTask->actual_cost, 2) }}.</p>

                    @if ($decidingCandidate && $decidingCandidate['kind'] === 'none')
                        <p class="text-xs text-warning">No tenancy could be matched automatically — select one below, or mark it a landlord responsibility.</p>
                    @elseif ($decidingCandidate && $decidingCandidate['kind'] === 'multiple')
                        <p class="text-xs text-warning">More than one tenancy record overlaps this task — this needs a manual pick, not a guess.</p>
                    @elseif ($decidingCandidate && $decidingCandidate['kind'] === 'single')
                        <p class="text-xs text-teal">Candidate: {{ $decidingCandidate['tenancy']->tenant->name }}{{ $decidingTask->complaint_id ? ' (reported this complaint)' : ' (occupied the unit on the relevant date)' }} — a candidate signal only, not proof. Confirm or choose someone else.</p>
                    @endif

                    <form wire:submit="confirmTenantLiability" class="space-y-3">
                        <div>
                            <x-input-label for="liability_tenancy_id" value="Tenant liable" />
                            <select id="liability_tenancy_id" wire:model="liability_tenancy_id" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                                <option value="">Select a tenancy…</option>
                                @foreach ($decidingTenancies as $t)
                                    <option value="{{ $t->id }}">{{ $t->tenant->name }} ({{ $t->move_in_date->format('M Y') }}{{ $t->move_out_date ? ' – '.$t->move_out_date->format('M Y') : ' – present' }})</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('liability_tenancy_id')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="liability_reason" value="Reason / evidence" />
                            <textarea id="liability_reason" wire:model="liability_reason" rows="2" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                            <x-input-error :messages="$errors->get('liability_reason')" class="mt-1" />
                        </div>
                        <div class="flex items-center gap-3">
                            <x-primary-button type="submit">Confirm Tenant Liability</x-primary-button>
                            <button type="button" wire:click="markLandlordResponsible" class="text-sm text-brand-slate hover:text-charcoal underline">Mark Landlord Responsibility Instead</button>
                        </div>
                    </form>
                    <button type="button" wire:click="cancelLiabilityDecision" class="text-xs text-brand-slate hover:text-charcoal">Cancel</button>
                </div>
            </div>
        @endif

        {{-- New Vendor modal --}}
        @if ($showVendorForm)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50" wire:click.self="$set('showVendorForm', false)">
                <form wire:submit="saveVendor" class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-md space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">New Vendor</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="vendor_name" value="Name" />
                            <x-text-input id="vendor_name" type="text" wire:model="vendor_name" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('vendor_name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="vendor_company" value="Company (optional)" />
                            <x-text-input id="vendor_company" type="text" wire:model="vendor_company" class="mt-1 block w-full" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="vendor_phone" value="Phone" />
                            <x-text-input id="vendor_phone" type="text" wire:model="vendor_phone" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('vendor_phone')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="vendor_email" value="Email (optional)" />
                            <x-text-input id="vendor_email" type="email" wire:model="vendor_email" class="mt-1 block w-full" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="vendor_category" value="Category" />
                            <x-text-input id="vendor_category" type="text" wire:model="vendor_category" class="mt-1 block w-full" placeholder="e.g. Plumbing" />
                            <x-input-error :messages="$errors->get('vendor_category')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="vendor_rate" value="Rate (optional)" />
                            <x-text-input id="vendor_rate" type="text" wire:model="vendor_rate" class="mt-1 block w-full" placeholder="e.g. KSh 1,500/visit" />
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Vendor</x-primary-button>
                        <button type="button" wire:click="$set('showVendorForm', false)" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- Complaint thread modal --}}
        @if ($showComplaintThread && $activeComplaint)
            <div class="fixed inset-0 bg-charcoal/40 flex items-center justify-center z-50 p-4" wire:click.self="closeComplaintThread">
                <div class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-lg space-y-4 max-h-[85vh] overflow-y-auto">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-charcoal">{{ $activeComplaint->subject }}</h3>
                            <p class="text-xs text-brand-slate">{{ $activeComplaint->tenant?->name }} · {{ $activeComplaint->house?->house_number }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-status-badge :status="$activeComplaint->status" />
                            <button type="button" wire:click="closeComplaintThread" class="text-brand-slate hover:text-charcoal text-xs">Close</button>
                        </div>
                    </div>

                    <div class="border border-brand-border rounded-md divide-y divide-gray-100 max-h-80 overflow-y-auto">
                        @forelse ($complaintUpdates as $update)
                            @php $isMe = $update->user_id === auth()->id(); @endphp
                            <div class="px-4 py-2.5">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-medium {{ $isMe ? 'text-teal' : 'text-navy' }}">
                                        {{ $isMe ? 'You' : ($update->user?->name ?? $activeComplaint->tenant?->name ?? 'Tenant') }}
                                    </span>
                                    <span class="text-xs text-gray-400">{{ $update->created_at->format('d M Y H:i') }}</span>
                                </div>
                                @if ($update->note)
                                    <p class="text-sm text-charcoal mt-1">{{ $update->note }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="px-4 py-6 text-center text-gray-400 text-sm">No updates yet.</p>
                        @endforelse
                    </div>

                    @if ($activeComplaint->isClosed())
                        <p class="text-xs text-brand-slate italic">This conversation is closed — the tenant confirmed the fix. Replies are disabled.</p>
                    @else
                        <form wire:submit="postComplaintUpdate" class="space-y-2">
                            <textarea wire:model="complaintNote" rows="3" class="block w-full rounded-md border-brand-border text-sm" placeholder="Reply to the tenant…"></textarea>
                            <x-input-error :messages="$errors->get('complaintNote')" class="mt-1" />
                            <x-primary-button type="submit">Send Reply</x-primary-button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
