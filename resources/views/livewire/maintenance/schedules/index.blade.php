    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-brand-slate">
                        <a href="{{ route('manage.maintenance.index') }}" wire:navigate class="hover:underline">Maintenance</a>
                    </p>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">Preventive Maintenance Schedules</h2>
                </div>
                <a href="{{ route('manage.maintenance.schedules.create') }}" wire:navigate class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">+ New Schedule</a>
            </div>
        </x-slot>

        <div class="p-6">
            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Title</th>
                            <th class="text-left px-5 py-2 font-medium">Property</th>
                            <th class="text-left px-5 py-2 font-medium">Frequency</th>
                            <th class="text-left px-5 py-2 font-medium">Next Due</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-right px-5 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($schedules as $schedule)
                            <tr class="hover:bg-gray-50 {{ $schedule->is_active && $schedule->next_due_date->isPast() ? 'bg-warning/5' : '' }}">
                                <td class="px-5 py-2.5 font-medium text-charcoal">{{ $schedule->title }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $schedule->property->name }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">Every {{ $schedule->frequency_months }} month{{ $schedule->frequency_months === 1 ? '' : 's' }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $schedule->next_due_date->format('d M Y') }}</td>
                                <td class="px-5 py-2.5">
                                    <x-status-badge :status="$schedule->is_active ? 'active' : 'inactive'" />
                                </td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap space-x-3">
                                    <a href="{{ route('manage.maintenance.schedules.edit', $schedule) }}" wire:navigate class="text-xs text-teal hover:underline">Edit</a>
                                    <button wire:click="toggleActive({{ $schedule->id }})" class="text-xs text-brand-slate hover:underline">{{ $schedule->is_active ? 'Pause' : 'Resume' }}</button>
                                    <button wire:click="delete({{ $schedule->id }})" wire:confirm="Delete this schedule?" class="text-xs text-danger hover:underline">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">No preventive maintenance schedules yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
