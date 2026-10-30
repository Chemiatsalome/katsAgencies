    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Properties</h2>
                @can('create', \App\Models\Property::class)
                    <div class="flex items-center gap-2">
                        <a href="{{ route('manage.properties.create') }}" wire:navigate class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-navy text-white text-xs sm:text-sm font-medium rounded-md hover:bg-navy-dark">
                            + Add Property
                        </a>
                        <a href="{{ route('manage.properties.import') }}" wire:navigate class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 border border-brand-border text-charcoal text-xs sm:text-sm font-medium rounded-md hover:bg-gray-50">
                            Import Properties
                        </a>
                    </div>
                @endcan
            </div>
        </x-slot>

        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
                @if (session('status'))
                    <div class="bg-teal/10 border border-teal/30 text-charcoal text-sm rounded-md px-4 py-3">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($properties->isEmpty())
                    <x-empty-state icon="properties" title="No properties yet" description="Add your first property to start tracking units, tenants and rent.">
                        @can('create', \App\Models\Property::class)
                            <x-slot name="action">
                                <a href="{{ route('manage.properties.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md shadow-sm hover:bg-navy-dark hover:shadow transition">
                                    + Add Property
                                </a>
                            </x-slot>
                        @endcan
                    </x-empty-state>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach ($properties as $property)
                            <div wire:key="property-{{ $property->id }}">
                                <x-property-card :property="$property" />
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        <x-per-page-select :paginator="$properties" />
                    </div>
                @endif
            </div>
        </div>
    </div>
