    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Documents</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="max-w-xs">
                <label class="block text-xs font-medium text-brand-slate mb-1">Category</label>
                <select wire:model.live="category" class="rounded-md border-brand-border text-sm w-full">
                    <option value="all">All Categories</option>
                    @foreach ($categoryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">File</th>
                            <th class="text-left px-5 py-2 font-medium">Category</th>
                            <th class="text-left px-5 py-2 font-medium">Attached To</th>
                            <th class="text-left px-5 py-2 font-medium">Uploaded</th>
                            <th class="text-right px-5 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($documents as $document)
                            @php
                                $subject = $document->documentable;
                                $label = match (true) {
                                    $subject instanceof \App\Models\Property => 'Property: '.$subject->name,
                                    $subject instanceof \App\Models\House => 'Unit: '.$subject->house_number,
                                    $subject instanceof \App\Models\Tenancy => 'Tenancy: '.$subject->tenant->name,
                                    $subject instanceof \App\Models\Tenant => 'Tenant: '.$subject->name,
                                    $subject instanceof \App\Models\Expense => 'Expense: '.($subject->vendor ?: $subject->description ?: '—'),
                                    $subject instanceof \App\Models\MaintenanceTask => 'Maintenance: '.$subject->title,
                                    default => '—',
                                };
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-2.5">
                                    <a href="{{ $document->url() }}" target="_blank" class="text-teal hover:underline">{{ $document->original_filename }}</a>
                                </td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $categoryOptions[$document->category] ?? $document->category }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $label }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $document->created_at->format('d M Y') }}</td>
                                <td class="px-5 py-2.5 text-right">
                                    <button wire:click="delete({{ $document->id }})" wire:confirm="Delete this document?" class="text-xs text-danger hover:underline">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-6 text-center text-gray-400">No documents uploaded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-per-page-select :paginator="$documents" />
        </div>
    </div>
