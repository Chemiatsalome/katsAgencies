    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Import Properties & Units</h2>
                <a href="{{ route('manage.properties.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Back to Properties</a>
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (session('status'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">{{ session('status') }}</div>
                @endif

                @if (! $previewed)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                        <p class="text-sm text-brand-slate">
                            Upload a CSV with one row per unit. Columns: <span class="font-medium text-charcoal">Property, Block, Unit, Unit Type, Rent, Deposit</span>,
                            plus optional <span class="font-medium text-charcoal">Property Type, Property Address, Bedrooms, Electricity, Water</span>.
                        </p>
                        <p class="text-xs text-brand-slate">
                            A property or block named in the file that doesn't exist yet is created automatically, so you don't need to set them up first.
                            Unit Type accepts Bedsitter, Studio, SQ, One Bedroom, Two Bedroom, Three Bedroom, Four Bedroom, House, Maisonette, Shop, or Office.
                            Electricity/Water accept Shared, Separate Meter, or Included (left blank defaults to Separate Meter / Shared).
                        </p>

                        <div>
                            <input type="file" wire:model="file" accept=".csv,.txt" class="block w-full text-sm">
                            <x-input-error :messages="$errors->get('file')" class="mt-1" />
                        </div>

                        <x-primary-button wire:click="preview" wire:loading.attr="disabled" type="button">
                            Validate File
                        </x-primary-button>
                    </div>
                @elseif (! $imported)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                        <div class="grid grid-cols-3 gap-3 text-center text-sm">
                            <div class="rounded-md bg-green-50 p-3">
                                <div class="text-lg font-semibold text-green-700">{{ $summary['valid'] }}</div>
                                <div class="text-green-700 text-xs">Valid</div>
                            </div>
                            <div class="rounded-md bg-red-50 p-3">
                                <div class="text-lg font-semibold text-danger">{{ $summary['invalid'] }}</div>
                                <div class="text-danger text-xs">Invalid</div>
                            </div>
                            <div class="rounded-md bg-amber-50 p-3">
                                <div class="text-lg font-semibold text-amber-700">{{ $summary['duplicate'] }}</div>
                                <div class="text-amber-700 text-xs">Duplicate</div>
                            </div>
                        </div>

                        <div class="overflow-x-auto max-h-96 overflow-y-auto border border-brand-border rounded-md">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-brand-slate sticky top-0">
                                    <tr>
                                        <th class="text-left px-3 py-2 font-medium">Property</th>
                                        <th class="text-left px-3 py-2 font-medium">Block</th>
                                        <th class="text-left px-3 py-2 font-medium">Unit</th>
                                        <th class="text-left px-3 py-2 font-medium">Type</th>
                                        <th class="text-right px-3 py-2 font-medium">Rent</th>
                                        <th class="text-left px-3 py-2 font-medium">Result</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($rows as $row)
                                        <tr class="{{ $row['bucket'] === 'valid' ? '' : 'bg-red-50/30' }}">
                                            <td class="px-3 py-2">{{ $row['property'] }}</td>
                                            <td class="px-3 py-2">{{ $row['block'] }}</td>
                                            <td class="px-3 py-2">{{ $row['unit'] }}</td>
                                            <td class="px-3 py-2">{{ $row['unit_type'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $row['rent'] }}</td>
                                            <td class="px-3 py-2">
                                                @if ($row['bucket'] === 'valid')
                                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700">Valid</span>
                                                @else
                                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">{{ ucfirst($row['bucket']) }}</span>
                                                    <span class="block text-[11px] text-brand-slate mt-0.5">{{ $row['reason'] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center gap-3">
                            @if ($summary['valid'] > 0)
                                <x-primary-button wire:click="commit" type="button">
                                    Import {{ $summary['valid'] }} Valid Unit{{ $summary['valid'] === 1 ? '' : 's' }}
                                </x-primary-button>
                            @endif
                            <button type="button" wire:click="startOver" class="text-sm text-brand-slate hover:text-charcoal">Upload a different file</button>
                        </div>
                    </div>
                @else
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                        <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">
                            Created {{ $createdPropertiesCount }} new propert{{ $createdPropertiesCount === 1 ? 'y' : 'ies' }},
                            {{ $createdBlocksCount }} new block{{ $createdBlocksCount === 1 ? '' : 's' }}, and
                            {{ $createdHousesCount }} new unit{{ $createdHousesCount === 1 ? '' : 's' }}.
                        </div>

                        <div class="flex items-center gap-3 pt-3 border-t border-brand-border">
                            <a href="{{ route('manage.properties.index') }}" wire:navigate class="text-sm text-teal hover:underline">Go to Properties</a>
                            <a href="{{ route('manage.tenants.import') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Now Import Tenants</a>
                            <button type="button" wire:click="startOver" class="text-sm text-brand-slate hover:text-charcoal">Import another file</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
