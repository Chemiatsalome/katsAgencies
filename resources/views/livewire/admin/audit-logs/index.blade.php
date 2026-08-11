    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Audit Logs</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="causerFilter" class="rounded-md border-brand-border text-sm">
                    <option value="all">All users</option>
                    <option value="admin">Admin actions</option>
                    <option value="landlord">Landlord actions</option>
                </select>
                <select wire:model.live="subjectType" class="rounded-md border-brand-border text-sm">
                    <option value="all">All record types</option>
                    @foreach ($subjectTypes as $type)
                        <option value="{{ $type }}">{{ class_basename($type) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">When</th>
                            <th class="text-left px-5 py-2 font-medium">User</th>
                            <th class="text-left px-5 py-2 font-medium">Event</th>
                            <th class="text-left px-5 py-2 font-medium">Record</th>
                            <th class="text-left px-5 py-2 font-medium">Description</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($entries as $entry)
                            <tr wire:key="activity-{{ $entry->id }}">
                                <td class="px-5 py-2.5 text-brand-slate whitespace-nowrap">{{ $entry->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-5 py-2.5 text-charcoal">{{ $entry->causer?->name ?? 'System' }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$entry->event ?? 'updated'" /></td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $entry->description }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-6 text-center text-gray-400">No activity recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $entries->links() }}</div>
        </div>
    </div>
