    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Contract Templates</h2>
                <a href="{{ route('manage.contract-templates.create') }}" wire:navigate class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">+ New Template</a>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-success/10 text-success text-sm px-4 py-2">{{ session('status') }}</div>
            @endif

            <p class="text-sm text-brand-slate max-w-2xl">
                Draft as many templates as you need — one per property or apartment type, or a single one that covers everything. Each property uses its own default if it has one, otherwise the "All Properties" fallback below.
            </p>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Name</th>
                            <th class="text-left px-5 py-2 font-medium">Applies To</th>
                            <th class="text-left px-5 py-2 font-medium">Default</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-right px-5 py-2 font-medium">Contracts Generated</th>
                            <th class="text-right px-5 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($templates as $template)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-charcoal font-medium">{{ $template->name }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $template->property?->name ?? 'All Properties' }}</td>
                                <td class="px-5 py-3">
                                    @if ($template->is_default)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-teal/10 text-teal">Default</span>
                                    @else
                                        <button wire:click="setDefault({{ $template->id }})" class="text-xs text-brand-slate hover:underline">Set as default</button>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <x-status-badge :status="$template->is_active ? 'active' : 'inactive'" />
                                </td>
                                <td class="px-5 py-3 text-right text-brand-slate">{{ $template->contracts_count }}</td>
                                <td class="px-5 py-3 text-right space-x-3">
                                    <a href="{{ route('manage.contract-templates.edit', $template) }}" wire:navigate class="text-xs text-teal hover:underline">Edit</a>
                                    <button wire:click="toggleActive({{ $template->id }})" class="text-xs text-brand-slate hover:underline">
                                        {{ $template->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-center text-gray-400">No contract templates yet — create one to start auto-generating tenancy agreements.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
