    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">{{ $template ? 'Edit Contract Template' : 'New Contract Template' }}</h2>
        </x-slot>

        <div class="p-6">
            <div class="max-w-4xl grid grid-cols-1 lg:grid-cols-3 gap-6">
                <form wire:submit="save" class="lg:col-span-2 bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5">
                    <div>
                        <x-input-label for="name" value="Template Name" />
                        <x-text-input id="name" type="text" wire:model="name" class="mt-1 block w-full" placeholder="e.g. Residential Tenancy Agreement" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="property_id" value="Applies To" />
                        <select id="property_id" wire:model="property_id" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="">All Properties (fallback default)</option>
                            @foreach ($properties as $property)
                                <option value="{{ $property->id }}">{{ $property->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-brand-slate mt-1">Scope this agreement to one property, or leave it on "All Properties" as the fallback used by any property without its own.</p>
                        <x-input-error :messages="$errors->get('property_id')" class="mt-1" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <x-input-label for="body" value="Agreement Text" />
                            <button
                                type="button"
                                wire:click="useDefaultTemplate"
                                wire:confirm="{{ $body ? 'This will replace the current text with the default template. Continue?' : '' }}"
                                class="text-xs text-teal hover:underline"
                            >Start from default template</button>
                        </div>
                        <div class="mb-2 rounded-md bg-teal/5 border border-teal/20 p-3 text-xs text-brand-slate">
                            Leave anything written like <code class="font-mono text-teal">@{{tenant_name}}</code> exactly as it is — those are filled in automatically by the system for each tenant, you don't need to touch them. Only edit the ordinary sentences around them — for example, writing in a clause like
                            <span class="italic">"After 6 months of occupancy, tenants cover repair costs, with technicians provided by the landlord. Deposits are forfeited for occupancy periods under 6 months."</span>
                            Add as many more clauses as you'd like, in whatever wording you prefer — or paste in an existing contract you already use and just slot the <code class="font-mono text-teal">@{{...}}</code> placeholders into it where the tenant's details should go.
                        </div>
                        @if ($drawnByNameMissing)
                            <div class="mb-2 rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-700">
                                <code class="font-mono">@{{drawn_by_name}}</code> will show up blank on every generated contract until you set who draws up the agreement.
                                <a href="{{ route('manage.settings.index') }}?tab=contract_letterhead" wire:navigate class="font-medium underline hover:text-amber-800">Set it under Settings → Contract Letterhead →</a>
                            </div>
                        @endif
                        <textarea id="body" wire:model="body" rows="16" class="mt-1 block w-full rounded-md border-brand-border font-mono text-xs"></textarea>
                        <x-input-error :messages="$errors->get('body')" class="mt-1" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-charcoal">
                        <input type="checkbox" wire:model="is_default" class="rounded border-brand-border" />
                        Use as the default template for {{ $property_id ? 'this property' : 'any property without its own default' }}
                    </label>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">Save Template</x-primary-button>
                        <a href="{{ route('manage.contract-templates.index') }}" wire:navigate class="text-sm text-brand-slate hover:text-charcoal">Cancel</a>
                    </div>
                </form>

                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5 h-fit">
                    <h3 class="text-sm font-semibold text-charcoal mb-3">Placeholders</h3>
                    <p class="text-xs text-brand-slate mb-3">Click a token to copy it, then paste it into the agreement text. Each is replaced with the tenancy's actual details when a contract is generated.</p>
                    <ul class="space-y-2">
                        @foreach ($placeholders as $token => $description)
                            <li>
                                <button
                                    type="button"
                                    x-data
                                    x-on:click="navigator.clipboard.writeText('{{ $token }}')"
                                    class="font-mono text-xs text-teal hover:underline"
                                >{{ $token }}</button>
                                <p class="text-xs text-brand-slate">{{ $description }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
