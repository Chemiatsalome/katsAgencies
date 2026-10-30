<div class="space-y-4">
    @if (session('status'))
        <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif
    <x-input-error :messages="$errors->get('delete')" />

    @can('create', \App\Models\Document::class)
        <form wire:submit="save" class="bg-white shadow-sm rounded-lg border border-brand-border p-5 space-y-4">
            <h3 class="text-sm font-semibold text-charcoal">Upload Document</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="category" value="Category" />
                    <select id="category" wire:model="category" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                        @foreach ($uploadableCategoryOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($model instanceof \App\Models\Tenancy)
                        <p class="text-xs text-brand-slate mt-1">For the lease agreement itself, use the Contract section above instead — it keeps versions and signatures tracked.</p>
                    @endif
                </div>
                <div>
                    <x-input-label for="file" value="File (PDF, image, or Word doc — max 10MB)" />
                    <input type="file" id="file" wire:model="file" class="mt-1 block w-full text-sm" />
                    <div wire:loading wire:target="file" class="text-xs text-brand-slate mt-1">Uploading…</div>
                    <x-input-error :messages="$errors->get('file')" class="mt-1" />
                </div>
            </div>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">Upload</x-primary-button>
        </form>
    @endcan

    <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-brand-slate">
                <tr>
                    <th class="text-left px-5 py-2 font-medium">File</th>
                    <th class="text-left px-5 py-2 font-medium">Category</th>
                    <th class="text-left px-5 py-2 font-medium">Uploaded</th>
                    <th class="text-right px-5 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($documents as $document)
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-2.5">
                            <a href="{{ $document->url() }}" target="_blank" class="text-teal hover:underline">{{ $document->original_filename }}</a>
                        </td>
                        <td class="px-5 py-2.5 text-brand-slate">{{ $categoryOptions[$document->category] ?? $document->category }}</td>
                        <td class="px-5 py-2.5 text-brand-slate">{{ $document->created_at->format('d M Y') }}</td>
                        <td class="px-5 py-2.5 text-right">
                            @if (! $document->contract()->exists())
                                @can('delete', $document)
                                    <button wire:click="delete({{ $document->id }})" wire:confirm="Delete this document?" class="text-xs text-danger hover:underline">Delete</button>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-6 text-center text-gray-400">No documents uploaded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
