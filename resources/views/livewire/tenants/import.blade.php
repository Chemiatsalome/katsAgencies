    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Import Tenants</h2>
                <a href="{{ route('manage.tenants.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Back to Tenants</a>
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
                            Upload a CSV file with columns: <span class="font-medium text-charcoal">Property, Block, Unit, Name, Phone, Rent, Due Date</span>,
                            plus optional <span class="font-medium text-charcoal">Move In Date</span>.
                            Due Date is the day of the month rent is due (defaults to 1 if left blank).
                        </p>
                        <p class="text-xs text-brand-slate">
                            Already had this tenant before you started using Kats Agencies? Put their real, past move-in date in Move In Date (format YYYY-MM-DD).
                            Rent from before your <a href="{{ route('manage.settings.index', ['tab' => 'financial']) }}" wire:navigate class="text-teal hover:underline">Financial Start Date</a> is
                            assumed already settled, so this won't manufacture arrears that never happened. Leave it blank for a tenant actually moving in today.
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
                        <div class="grid grid-cols-5 gap-3 text-center text-sm">
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
                            <div class="rounded-md bg-amber-50 p-3">
                                <div class="text-lg font-semibold text-amber-700">{{ $summary['missing_unit'] }}</div>
                                <div class="text-amber-700 text-xs">Missing Unit</div>
                            </div>
                            <div class="rounded-md bg-amber-50 p-3">
                                <div class="text-lg font-semibold text-amber-700">{{ $summary['unknown_property'] }}</div>
                                <div class="text-amber-700 text-xs">Unknown Property</div>
                            </div>
                        </div>

                        <div class="overflow-x-auto max-h-96 overflow-y-auto border border-brand-border rounded-md">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-brand-slate sticky top-0">
                                    <tr>
                                        <th class="text-left px-3 py-2 font-medium">Property</th>
                                        <th class="text-left px-3 py-2 font-medium">Block</th>
                                        <th class="text-left px-3 py-2 font-medium">Unit</th>
                                        <th class="text-left px-3 py-2 font-medium">Name</th>
                                        <th class="text-left px-3 py-2 font-medium">Phone</th>
                                        <th class="text-right px-3 py-2 font-medium">Rent</th>
                                        <th class="text-left px-3 py-2 font-medium">Move In Date</th>
                                        <th class="text-left px-3 py-2 font-medium">Result</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($rows as $row)
                                        <tr class="{{ $row['bucket'] === 'valid' ? '' : 'bg-red-50/30' }}">
                                            <td class="px-3 py-2">{{ $row['property'] }}</td>
                                            <td class="px-3 py-2">{{ $row['block'] }}</td>
                                            <td class="px-3 py-2">{{ $row['unit'] }}</td>
                                            <td class="px-3 py-2">{{ $row['name'] }}</td>
                                            <td class="px-3 py-2">{{ $row['phone'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $row['rent'] }}</td>
                                            <td class="px-3 py-2">{{ $row['move_in_date'] ?: 'Today' }}</td>
                                            <td class="px-3 py-2">
                                                @if ($row['bucket'] === 'valid')
                                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700">Valid</span>
                                                @else
                                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">{{ ucfirst(str_replace('_', ' ', $row['bucket'])) }}</span>
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
                                    Import {{ $summary['valid'] }} Valid Record{{ $summary['valid'] === 1 ? '' : 's' }}
                                </x-primary-button>
                            @endif
                            <button type="button" wire:click="startOver" class="text-sm text-brand-slate hover:text-charcoal">Upload a different file</button>
                        </div>
                    </div>
                @else
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-4">
                        <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-md px-4 py-2">
                            Imported {{ count($createdTenants) }} tenant(s). They now appear in your tenant list and their units are marked occupied.
                        </div>

                        <p class="text-sm text-brand-slate">
                            These tenants were added without a portal login. Send each one a registration invitation so they can activate their own account and log in.
                        </p>

                        <div class="divide-y divide-gray-100">
                            @foreach ($createdTenants as $tenant)
                                <div class="flex items-center justify-between py-2 text-sm">
                                    <span class="text-charcoal">{{ $tenant->name }} ({{ $tenant->phone }})</span>
                                    @if (! $tenant->user_id)
                                        <button wire:click="sendInvitation({{ $tenant->id }})" class="text-teal hover:underline text-xs font-medium">
                                            Send Registration Invitation
                                        </button>
                                    @else
                                        <span class="text-xs text-brand-slate">Already has portal login</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-3 pt-3 border-t border-brand-border">
                            <a href="{{ route('manage.tenants.index') }}" wire:navigate class="text-sm text-teal hover:underline">Go to Tenants</a>
                            <a href="{{ route('manage.tenant-invitations.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">View Invitations</a>
                            <button type="button" wire:click="startOver" class="text-sm text-brand-slate hover:text-charcoal">Import another file</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
