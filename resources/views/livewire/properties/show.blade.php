    <div>
        @if ($justOnboarded)
            @push('header-back-url'){{ route('manage.properties.index') }}@endpush
        @endif

        <x-slot name="header">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-brand-slate">
                        <a href="{{ route('manage.properties.index') }}" wire:navigate class="hover:underline">Properties</a>
                    </p>
                    <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $property->name }}</h2>
                    <p class="text-sm text-brand-slate">{{ $property->address }}</p>
                </div>
                <a href="{{ route('manage.properties.edit', $property) }}" wire:navigate class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 border border-brand-border text-charcoal text-xs sm:text-sm font-medium rounded-md hover:bg-offwhite">
                    Edit Property
                </a>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="rounded-xl overflow-hidden shadow-sm h-40 sm:h-56">
                <x-property-photo :property="$property" />
            </div>

            @php
                $vacantTenancyCreateUrl = route('manage.tenancies.create', $onlyVacantHouseId
                    ? ['house_id' => $onlyVacantHouseId]
                    : ['property_id' => $property->id]);
            @endphp

            @if ($justOnboarded)
                <div x-data="{ dismissed: false }" x-show="!dismissed" class="bg-teal/10 border border-teal/30 rounded-lg px-5 py-4 flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <p class="text-sm font-semibold text-charcoal">Your property has been added. Would you like to add tenants now?</p>
                        <p class="text-xs text-brand-slate mt-0.5">Units are ready and vacant — you can add a tenant directly or send them an invitation to register themselves. You can also skip this and come back anytime.</p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <a href="{{ $vacantTenancyCreateUrl }}" wire:navigate class="inline-flex items-center px-3 py-1.5 bg-navy text-white text-xs font-medium rounded-md hover:bg-navy-dark">
                            Add a tenant now
                        </a>
                        <a href="{{ route('manage.tenant-invitations.create') }}" wire:navigate class="inline-flex items-center px-3 py-1.5 border border-brand-border text-charcoal text-xs font-medium rounded-md hover:bg-white">
                            Invite a tenant
                        </a>
                        <button type="button" @click="dismissed = true" class="text-xs text-brand-slate hover:text-charcoal">I'll do this later</button>
                    </div>
                </div>
            @elseif ($vacantUnits > 0)
                {{-- Not tied to any one tab or visit — every time this property has a vacant unit, adding a tenant for it should be one click away, not something the landlord has to go hunting for. --}}
                <div class="bg-white border border-warning/30 rounded-lg px-5 py-3 flex items-center justify-between flex-wrap gap-3">
                    <p class="text-sm text-charcoal">
                        <span class="font-semibold">{{ $vacantUnits }}</span> {{ Str::plural('unit', $vacantUnits) }} {{ $vacantUnits === 1 ? 'is' : 'are' }} currently vacant on this property.
                    </p>
                    <div class="flex items-center gap-3 shrink-0">
                        <a href="{{ $vacantTenancyCreateUrl }}" wire:navigate class="inline-flex items-center px-3 py-1.5 bg-navy text-white text-xs font-medium rounded-md hover:bg-navy-dark">
                            Add a tenant
                        </a>
                        <a href="{{ route('manage.tenant-invitations.create') }}" wire:navigate class="inline-flex items-center px-3 py-1.5 border border-brand-border text-charcoal text-xs font-medium rounded-md hover:bg-offwhite">
                            Invite a tenant
                        </a>
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="Units" :value="$totalUnits" />
                <x-stat-tile label="Occupied" :value="$occupiedUnits" tone="good" />
                <x-stat-tile label="Vacant" :value="$vacantUnits" />
                <x-stat-tile label="Occupancy" value="{{ $occupancyRate }}%" />
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="Expected Rent" value="KSh {{ number_format($expectedRent) }}" />
                <x-stat-tile label="Collected" value="KSh {{ number_format($collectedRent) }}" tone="good" />
                <x-stat-tile label="Expenses" value="KSh {{ number_format($expenses) }}" />
                <x-stat-tile label="Net Income" value="KSh {{ number_format($netIncome) }}" tone="{{ $netIncome >= 0 ? 'good' : 'critical' }}" />
            </div>

            <div class="flex gap-2 flex-wrap border-b border-brand-border">
                @foreach (['overview' => 'Overview', 'units' => 'Units', 'tenants' => 'Tenants', 'rent' => 'Rent', 'expenses' => 'Expenses', 'maintenance' => 'Maintenance', 'contracts' => 'Contracts', 'documents' => 'Documents', 'performance' => 'Performance'] as $value => $label)
                    <button wire:click="$set('tab', '{{ $value }}')" class="px-3 py-2 text-sm border-b-2 {{ $tab === $value ? 'border-navy text-navy font-medium' : 'border-transparent text-brand-slate hover:text-charcoal' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'overview')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 grid grid-cols-2 gap-6 text-sm">
                    <div>
                        <p class="text-brand-slate">Type</p>
                        <p class="text-charcoal font-medium">{{ str($property->type)->replace('_', ' ')->title() }}</p>
                    </div>
                    <div>
                        <p class="text-brand-slate">Status</p>
                        <x-status-badge :status="$property->status" />
                    </div>
                    <div class="col-span-2">
                        <p class="text-brand-slate">Description</p>
                        <p class="text-charcoal">{{ $property->description ?: '—' }}</p>
                    </div>
                </div>
            @elseif ($tab === 'units')
                <div class="flex items-center justify-end">
                    @if ($blocks->count() === 1)
                        @can('create', [\App\Models\House::class, $blocks->first()])
                            <a href="{{ route('manage.houses.create', $blocks->first()) }}" wire:navigate class="inline-flex items-center px-3 py-1.5 bg-navy text-white text-xs font-medium rounded-md hover:bg-navy-dark">
                                + Add Unit
                            </a>
                        @endcan
                    @elseif ($blocks->count() > 1)
                        @can('create', \App\Models\House::class)
                            <a href="{{ route('manage.blocks.index', $property) }}" wire:navigate class="inline-flex items-center px-3 py-1.5 bg-navy text-white text-xs font-medium rounded-md hover:bg-navy-dark">
                                + Add Unit (choose a block)
                            </a>
                        @endcan
                    @endif
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Unit</th>
                                <th class="text-left px-5 py-2 font-medium">Type</th>
                                <th class="text-left px-5 py-2 font-medium">Rent</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="text-left px-5 py-2 font-medium">Tenant</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($houses as $house)
                                <tr>
                                    <td class="px-5 py-2.5 font-medium text-charcoal">
                                        <a href="{{ route('manage.houses.show', $house) }}" wire:navigate class="hover:underline">{{ $house->house_number }}</a>
                                    </td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ str($house->type)->replace('_', ' ')->title() }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">KSh {{ number_format($house->monthly_rent) }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$house->status" /></td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $house->currentTenancy?->tenant->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-6 text-center text-gray-400">No units yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @elseif ($tab === 'tenants')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                <th class="text-left px-5 py-2 font-medium">Unit</th>
                                <th class="text-left px-5 py-2 font-medium">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($tenancies as $tenancy)
                                <tr>
                                    <td class="px-5 py-2.5 font-medium text-charcoal">
                                        <a href="{{ route('manage.tenants.show', $tenancy->tenant) }}" wire:navigate class="hover:underline">{{ $tenancy->tenant->name }}</a>
                                    </td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $tenancy->house->house_number }}</td>
                                    <td class="px-5 py-2.5 {{ $tenancy->balance() > 0 ? 'text-danger' : 'text-brand-slate' }}">KSh {{ number_format($tenancy->balance()) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-8 text-center text-gray-400">
                                        @if ($vacantUnits > 0)
                                            No tenants yet.
                                            <a href="{{ $vacantTenancyCreateUrl }}" wire:navigate class="text-teal hover:underline">Add one of your {{ $vacantUnits }} vacant {{ Str::plural('unit', $vacantUnits) }} now →</a>
                                        @else
                                            No tenants yet.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @elseif ($tab === 'rent')
                <div class="flex items-center justify-end">
                    <a href="{{ route('manage.rent-charges.index', ['propertyId' => $property->id]) }}" wire:navigate class="text-sm text-teal hover:underline">
                        Open full rent ledger for this property →
                    </a>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                <th class="text-left px-5 py-2 font-medium">Period</th>
                                <th class="text-right px-5 py-2 font-medium">Expected</th>
                                <th class="text-right px-5 py-2 font-medium">Paid</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($rentCharges as $charge)
                                <tr>
                                    <td class="px-5 py-2.5 text-charcoal">{{ $charge->tenancy->tenant->name }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ \Carbon\Carbon::create()->month($charge->period_month)->format('M') }} {{ $charge->period_year }}</td>
                                    <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($charge->amount_expected) }}</td>
                                    <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($charge->amount_paid) }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$charge->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-6 text-center text-gray-400">No rent charges yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @elseif ($tab === 'expenses')
                <div class="flex items-center justify-between">
                    <a href="{{ route('manage.expenses.index', ['propertyId' => $property->id]) }}" wire:navigate class="text-sm text-teal hover:underline">
                        View all expenses for this property →
                    </a>
                    <a href="{{ route('manage.expenses.create', ['property_id' => $property->id]) }}" wire:navigate class="inline-flex items-center px-3 py-1.5 bg-navy text-white text-xs font-medium rounded-md hover:bg-navy-dark">
                        + Record Expense
                    </a>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Date</th>
                                <th class="text-left px-5 py-2 font-medium">Category</th>
                                <th class="text-left px-5 py-2 font-medium">Description</th>
                                <th class="text-right px-5 py-2 font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($expenseList as $expense)
                                <tr>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $expense->expense_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2.5 text-charcoal">{{ $expense->category->name }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $expense->description }}</td>
                                    <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($expense->amount) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No expenses recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @elseif ($tab === 'maintenance')
                <div class="flex items-center justify-end">
                    <a href="{{ route('manage.maintenance.index', ['propertyId' => $property->id]) }}" wire:navigate class="text-sm text-teal hover:underline">
                        Open maintenance for this property →
                    </a>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Task</th>
                                <th class="text-left px-5 py-2 font-medium">Vendor</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="text-right px-5 py-2 font-medium">Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($tasks as $task)
                                <tr>
                                    <td class="px-5 py-2.5 text-charcoal">{{ $task->title }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $task->vendor?->name ?? '—' }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$task->status" /></td>
                                    <td class="px-5 py-2.5 text-right text-brand-slate">{{ $task->actual_cost ? 'KSh '.number_format($task->actual_cost) : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400">No maintenance tasks yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @elseif ($tab === 'contracts')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Tenant</th>
                                <th class="text-left px-5 py-2 font-medium">Unit</th>
                                <th class="text-left px-5 py-2 font-medium">Lease Expiry</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($tenancies as $tenancy)
                                <tr>
                                    <td class="px-5 py-2.5 text-charcoal">
                                        <a href="{{ route('manage.tenancies.show', $tenancy) }}" wire:navigate class="hover:underline">{{ $tenancy->tenant->name }}</a>
                                    </td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $tenancy->house->house_number }}</td>
                                    <td class="px-5 py-2.5 text-brand-slate">{{ $tenancy->lease_end_date?->format('d M Y') ?? 'Open-ended' }}</td>
                                    <td class="px-5 py-2.5"><x-status-badge :status="$tenancy->status" /></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-gray-400">
                                        @if ($vacantUnits > 0)
                                            A tenancy needs to exist before a contract can be generated for it.
                                            <a href="{{ $vacantTenancyCreateUrl }}" wire:navigate class="text-teal hover:underline">Add a tenant first →</a>
                                        @else
                                            A tenancy needs to exist before a contract can be generated for it.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            @elseif ($tab === 'documents')
                <livewire:documents.manager :model="$property" :key="'docs-'.$property->id" />
            @elseif ($tab === 'performance')
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-brand-slate">Property Health:</span>
                        <x-status-badge :status="str($health['rating'])->slug('_')" />
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <x-stat-tile label="Occupancy" value="{{ $health['factors']['occupancy'] }}%" />
                        <x-stat-tile label="Collection" value="{{ $health['factors']['collection'] }}%" />
                        <x-stat-tile label="Arrears" value="KSh {{ number_format($health['factors']['arrears']) }}" />
                        <x-stat-tile label="Open Maintenance" :value="$health['factors']['open_maintenance']" />
                        <x-stat-tile label="Urgent Maintenance" :value="$health['factors']['urgent_maintenance']" tone="{{ $health['factors']['urgent_maintenance'] > 0 ? 'critical' : 'neutral' }}" />
                        <x-stat-tile label="Contracts Expiring" :value="$health['factors']['expiring_contracts']" />
                    </div>
                    @if ($health['factors']['issues']->isNotEmpty())
                        <div>
                            <p class="text-sm font-medium text-charcoal mb-1">Why this rating:</p>
                            <ul class="text-sm text-brand-slate list-disc list-inside">
                                @foreach ($health['factors']['issues'] as $issue)
                                    <li>{{ $issue }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <a href="{{ route('manage.analytics.performance') }}" wire:navigate class="inline-block text-sm text-teal hover:underline">View ROI &amp; performance analytics across all properties →</a>
                </div>
            @endif
        </div>
    </div>
