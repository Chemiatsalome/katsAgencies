    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Landlords</h2>
                <a href="{{ route('admin.landlords.applications') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                    View Applications
                </a>
            </div>
        </x-slot>

        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by name or email…" class="rounded-md border-brand-border text-sm" />
                <select wire:model.live="status" class="rounded-md border-brand-border text-sm">
                    <option value="all">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Pending</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate sticky top-0">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Landlord</th>
                                <th class="text-left px-5 py-2 font-medium">Contact</th>
                                <th class="text-left px-5 py-2 font-medium">Properties</th>
                                <th class="text-left px-5 py-2 font-medium">Units</th>
                                <th class="text-left px-5 py-2 font-medium">Occupied</th>
                                <th class="text-left px-5 py-2 font-medium">Vacant</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($landlords as $row)
                                <tr wire:key="landlord-{{ $row->user->id }}">
                                    <td class="px-5 py-3 font-medium text-charcoal">
                                        <a href="{{ route('admin.landlords.show', $row->user) }}" wire:navigate class="hover:underline">{{ $row->user->name }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $row->user->email }}</td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $row->propertiesCount }}</td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $row->unitsCount }}</td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $row->occupiedCount }}</td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $row->vacantCount }}</td>
                                    <td class="px-5 py-3"><x-status-badge :status="$row->user->status" /></td>
                                    <td class="px-5 py-3 text-right space-x-2">
                                        @if ($row->user->status === 'active')
                                            <button wire:click="updateStatus({{ $row->user->id }}, 'suspended')" wire:confirm="Suspend this landlord?" class="text-danger hover:underline text-xs">Suspend</button>
                                        @else
                                            <button wire:click="updateStatus({{ $row->user->id }}, 'active')" class="text-success hover:underline text-xs">Reactivate</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-6 text-center text-gray-400">No landlords found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>{{ $landlords->links() }}</div>
        </div>
    </div>
