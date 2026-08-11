    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Maintenance</h2>
                @if ($tab === 'scheduled')
                    <button wire:click="openTaskForm" class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">+ New Task</button>
                @elseif ($tab === 'vendors')
                    <button wire:click="openVendorForm" class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">+ New Vendor</button>
                @endif
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif

            <div class="flex gap-2 flex-wrap border-b border-brand-border">
                @foreach (['dashboard' => 'Dashboard', 'requests' => 'Requests', 'scheduled' => 'Scheduled', 'calendar' => 'Calendar', 'vendors' => 'Vendors'] as $value => $label)
                    <button wire:click="$set('tab', '{{ $value }}')" class="px-3 py-2 text-sm border-b-2 {{ $tab === $value ? 'border-navy text-navy font-medium' : 'border-transparent text-brand-slate hover:text-charcoal' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- DASHBOARD --}}
            @if ($tab === 'dashboard')
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <x-stat-tile label="Open Tasks" :value="$openCount" href="{{ route('manage.maintenance.index', ['tab' => 'scheduled', 'status' => 'open']) }}" />
                    <x-stat-tile label="Emergencies" :value="$emergencyCount" tone="{{ $emergencyCount > 0 ? 'critical' : 'neutral' }}" />
                    <x-stat-tile label="Scheduled Today" :value="$scheduledTodayCount" />
                    <x-stat-tile label="Preventive Tasks Due" :value="$overdueScheduleCount" href="{{ route('manage.maintenance.schedules.index') }}" tone="{{ $overdueScheduleCount > 0 ? 'warning' : 'neutral' }}" />
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="px-5 py-4 border-b border-brand-border">
                        <h3 class="text-sm font-semibold text-charcoal">Needs Attention</h3>
                    </div>
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
                            @forelse ($recentOpenTasks as $task)
                                <tr class="hover:bg-gray-50 cursor-pointer" wire:click="$set('tab', 'scheduled')">
                                    <td class="px-5 py-2.5 font-medium text-charcoal">{{ $task->title }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $task->property->name }}{{ $task->house ? ' — '.$task->house->house_number : '' }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$task->priority" /></td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$task->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No open maintenance tasks.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- REQUESTS (from tenant complaints) --}}
            @if ($tab === 'requests')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
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
                                    <td class="px-5 py-2.5 text-right">
                                        <button wire:click="convertToTask({{ $complaint->id }})" class="text-xs text-teal hover:underline">Convert to Task</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">No open tenant requests.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
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
                                    <td class="px-5 py-2.5 font-medium text-charcoal">{{ $task->title }}</td>
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
                        <select id="assign_vendor_id" wire:model="assign_vendor_id" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="">No vendor</option>
                            @foreach ($vendors as $vendor)
                                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                            @endforeach
                        </select>
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
                    <div>
                        <x-input-label for="complete_actual_cost" value="Actual Cost (KSh)" />
                        <x-text-input id="complete_actual_cost" type="number" step="0.01" wire:model="complete_actual_cost" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('complete_actual_cost')" class="mt-1" />
                        <p class="text-xs text-brand-slate mt-1">This will be recorded automatically as a property expense.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Mark Complete</x-primary-button>
                        <button type="button" wire:click="$set('showCompleteForm', false)" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                    </div>
                </form>
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
    </div>
