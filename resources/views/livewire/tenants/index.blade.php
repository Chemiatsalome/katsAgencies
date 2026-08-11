    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Tenants</h2>
                @can('create', \App\Models\Tenant::class)
                    <div class="flex items-center gap-2">
                        <a href="{{ route('manage.tenant-invitations.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-teal text-white text-sm font-medium rounded-md hover:opacity-90">
                            + Invite Tenant
                        </a>
                        <a href="{{ route('manage.tenancies.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 border border-brand-border text-charcoal text-sm font-medium rounded-md hover:bg-gray-50">
                            + Add Tenant
                        </a>
                        <a href="{{ route('manage.tenants.import') }}" wire:navigate class="inline-flex items-center px-4 py-2 border border-brand-border text-charcoal text-sm font-medium rounded-md hover:bg-gray-50">
                            Import Tenants
                        </a>
                    </div>
                @endcan
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-brand-slate">Property</label>
                        <select wire:model.live="propertyId" class="rounded-md border-brand-border text-sm">
                            <option value="all">All Properties</option>
                            @foreach ($properties as $property)
                                <option value="{{ $property->id }}">{{ $property->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="text-sm text-brand-slate">Block</label>
                        <select wire:model.live="blockId" class="rounded-md border-brand-border text-sm">
                            <option value="all">All Blocks</option>
                            @foreach ($blocks as $block)
                                <option value="{{ $block->id }}">{{ $block->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="text-sm text-brand-slate">Status</label>
                        <select wire:model.live="status" class="rounded-md border-brand-border text-sm">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="expiring_soon">Expiring Soon</option>
                            <option value="notice_given">Notice Given</option>
                            <option value="pending">Pending</option>
                            <option value="terminated">Terminated</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>

                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by name or phone…" class="rounded-md border-brand-border text-sm" />
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-brand-slate">
                                <tr>
                                    <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                    <th class="text-left px-5 py-2 font-medium">Property</th>
                                    <th class="text-left px-5 py-2 font-medium">Block</th>
                                    <th class="text-left px-5 py-2 font-medium">Unit</th>
                                    <th class="text-left px-5 py-2 font-medium">Phone</th>
                                    <th class="text-left px-5 py-2 font-medium">Rent</th>
                                    <th class="text-left px-5 py-2 font-medium">Pays By</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                    <th class="px-5 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($tenants as $tenant)
                                    @php($tenancy = $tenant->tenancies->first())
                                    <tr wire:key="tenant-{{ $tenant->id }}">
                                        <td class="px-5 py-3 font-medium text-charcoal">
                                            <a href="{{ route('manage.tenants.show', $tenant) }}" wire:navigate class="hover:underline">{{ $tenant->name }}</a>
                                        </td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy?->house->block->property->name ?? '—' }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy?->house->block->name ?? '—' }}</td>
                                        <td class="px-5 py-3 text-brand-slate">
                                            @if ($tenancy)
                                                <a href="{{ route('manage.tenancies.show', $tenancy) }}" wire:navigate class="text-teal hover:underline">{{ $tenancy->house->house_number }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenant->phone }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy ? 'KSh '.number_format($tenancy->monthly_rent) : '—' }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ \Illuminate\Support\Number::ordinal($tenancy?->dueDay() ?? $tenant->payment_due_day) }}</td>
                                        <td class="px-5 py-3">
                                            @if ($tenancy)
                                                <x-status-badge :status="$tenancy->status" />
                                            @else
                                                <span class="text-gray-400 text-xs">No tenancy</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right space-x-3">
                                            <a href="{{ route('manage.tenants.edit', $tenant) }}" wire:navigate class="text-brand-slate hover:text-charcoal">Edit</a>
                                            @can('delete', $tenant)
                                                <button wire:click="delete({{ $tenant->id }})" wire:confirm="Delete this tenant?" class="text-danger hover:text-danger/80">Delete</button>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-5 py-6 text-center text-gray-400">No tenants found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <x-per-page-select :paginator="$tenants" />
            </div>
        </div>
    </div>
