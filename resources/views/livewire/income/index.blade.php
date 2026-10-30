    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Other Income</h2>
                <a href="{{ route('manage.income.create') }}" wire:navigate class="px-3 py-1.5 text-sm rounded-md bg-navy text-white hover:bg-navy-dark">+ Add Income</a>
            </div>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="flex flex-wrap items-end gap-4">
                <div class="max-w-xs">
                    <x-stat-tile label="Total (filtered)" value="KSh {{ number_format($total, 2) }}" tone="good" />
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
                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Category</label>
                    <select wire:model.live="categoryId" class="rounded-md border-brand-border text-sm">
                        <option value="all">All Categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-brand-slate mb-1">Month</label>
                    <input type="month" wire:model.live="month" class="rounded-md border-brand-border text-sm" />
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Date</th>
                            <th class="text-left px-5 py-2 font-medium">Property</th>
                            <th class="text-left px-5 py-2 font-medium">Category</th>
                            <th class="text-left px-5 py-2 font-medium">Description</th>
                            <th class="text-right px-5 py-2 font-medium">Amount</th>
                            <th class="text-right px-5 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($incomes as $income)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-brand-slate">{{ $income->income_date->format('d M Y') }}</td>
                                <td class="px-5 py-3 text-charcoal">{{ $income->property->name }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $income->category?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $income->description ?: '—' }}</td>
                                <td class="px-5 py-3 text-right font-medium text-success">KSh {{ number_format($income->amount, 2) }}</td>
                                <td class="px-5 py-3 text-right space-x-3">
                                    <a href="{{ route('manage.income.edit', $income) }}" wire:navigate class="text-xs text-brand-slate hover:text-charcoal">Edit</a>
                                    <button wire:click="delete({{ $income->id }})" wire:confirm="Delete this income record?" class="text-xs text-danger hover:underline">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-center text-gray-400">No other income recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <x-per-page-select :paginator="$incomes" />
        </div>
    </div>
