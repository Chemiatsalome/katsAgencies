    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Tenancies</h2>
                @can('create', \App\Models\Tenancy::class)
                    <a href="{{ route('manage.tenancies.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                        + Move In Tenant
                    </a>
                @endcan
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                @if ($expiringSoon->isNotEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-warning/30 overflow-hidden">
                        <div class="px-5 py-3 border-b border-brand-border bg-warning/5">
                            <h3 class="text-sm font-semibold text-charcoal">Contracts Expiring Soon</h3>
                        </div>
                        <ul class="divide-y divide-gray-100">
                            @foreach ($expiringSoon as $expiring)
                                <li wire:key="expiring-{{ $expiring->id }}" class="px-5 py-3 flex items-center justify-between text-sm">
                                    <div>
                                        <span class="font-medium text-charcoal">{{ $expiring->tenant->name }}</span>
                                        <span class="text-brand-slate">— {{ $expiring->house->block->property->name }} / {{ $expiring->house->house_number }}</span>
                                        <span class="text-brand-slate">· Expires {{ $expiring->lease_end_date->format('d M Y') }}</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        @can('update', $expiring)
                                            <button wire:click="openRenew({{ $expiring->id }})" class="text-teal hover:underline text-xs font-medium">Renew</button>
                                            <a href="{{ route('manage.tenancies.show', $expiring) }}" wire:navigate class="text-brand-slate hover:text-charcoal text-xs font-medium">Edit</a>
                                            <a href="{{ route('manage.tenancies.show', $expiring) }}" wire:navigate class="text-danger hover:underline text-xs font-medium">Terminate</a>
                                        @endcan
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

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
                </div>

                <div class="flex gap-2 flex-wrap">
                    @foreach (['all' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'expiring_soon' => 'Expiring Soon', 'notice_given' => 'Notice Given', 'terminated' => 'Terminated', 'completed' => 'Completed'] as $value => $label)
                        <button wire:click="$set('status', '{{ $value }}')" class="px-3 py-1.5 text-sm rounded-md {{ $status === $value ? 'bg-navy text-white' : 'bg-white text-brand-slate border border-brand-border' }}">
                            {{ $label }}
                        </button>
                    @endforeach
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
                                    <th class="text-left px-5 py-2 font-medium">Monthly Rent</th>
                                    <th class="text-left px-5 py-2 font-medium">Move In</th>
                                    <th class="text-left px-5 py-2 font-medium">Balance</th>
                                    <th class="text-left px-5 py-2 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($tenancies as $tenancy)
                                    <tr wire:key="tenancy-{{ $tenancy->id }}" class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('manage.tenancies.show', $tenancy) }}'">
                                        <td class="px-5 py-3 font-medium text-charcoal">{{ $tenancy->tenant->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy->house->block->property->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy->house->block->name }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy->house->house_number }}</td>
                                        <td class="px-5 py-3 text-brand-slate">KSh {{ number_format($tenancy->monthly_rent) }}</td>
                                        <td class="px-5 py-3 text-brand-slate">{{ $tenancy->move_in_date->format('d M Y') }}</td>
                                        <td class="px-5 py-3 {{ $tenancy->balance() > 0 ? 'text-danger' : 'text-brand-slate' }}">KSh {{ number_format($tenancy->balance()) }}</td>
                                        <td class="px-5 py-3"><x-status-badge :status="$tenancy->status" /></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-5 py-6 text-center text-gray-400">No tenancies found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <x-per-page-select :paginator="$tenancies" />
            </div>
        </div>

        @if ($renewingTenancyId)
            <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="cancelRenew">
                <div class="bg-white rounded-lg shadow-lg border border-brand-border p-6 w-full max-w-sm space-y-4">
                    <h3 class="text-sm font-semibold text-charcoal">Renew Tenancy</h3>

                    <form wire:submit="renew" class="space-y-4">
                        <div>
                            <x-input-label for="renew_lease_end_date" value="New Tenancy End Date" />
                            <x-text-input id="renew_lease_end_date" type="date" wire:model="renew_lease_end_date" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('renew_lease_end_date')" class="mt-1" />
                        </div>

                        <div class="flex items-center gap-3">
                            <x-primary-button type="submit">Renew</x-primary-button>
                            <button type="button" wire:click="cancelRenew" class="text-sm text-brand-slate hover:text-charcoal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
