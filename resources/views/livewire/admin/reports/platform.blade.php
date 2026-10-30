    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Platform Reports</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 max-w-3xl mb-2">
                <x-stat-tile label="Landlords" :value="$totalLandlords" />
                <x-stat-tile label="Properties" :value="$totalProperties" />
                <x-stat-tile label="Units" :value="$totalUnits" />
            </div>

            <div class="grid gap-4 max-w-2xl">
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-charcoal">Landlord Summary Report</p>
                        <p class="text-xs text-brand-slate">Every landlord with their property/unit counts and occupancy.</p>
                    </div>
                    <button wire:click="exportLandlordSummary" class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-navy text-white text-xs sm:text-sm font-medium rounded-md hover:bg-navy-dark">
                        Export CSV
                    </button>
                </div>
                <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-charcoal">Platform Occupancy Report</p>
                        <p class="text-xs text-brand-slate">Every property's occupancy rate, across all landlords.</p>
                    </div>
                    <button wire:click="exportOccupancy" class="inline-flex items-center px-3 py-1.5 sm:px-4 sm:py-2 bg-navy text-white text-xs sm:text-sm font-medium rounded-md hover:bg-navy-dark">
                        Export CSV
                    </button>
                </div>
            </div>
        </div>
    </div>
