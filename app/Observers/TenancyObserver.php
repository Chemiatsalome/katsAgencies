<?php

namespace App\Observers;

use App\Models\Tenancy;
use App\Services\ContractService;
use Illuminate\Support\Facades\Log;

class TenancyObserver
{
    public function created(Tenancy $tenancy): void
    {
        if (in_array($tenancy->status, Tenancy::OCCUPYING_STATUSES, true)) {
            $tenancy->house->update(['status' => 'occupied']);
        }

        // Every path that creates a Tenancy (manual add, CSV import,
        // invite-accept, future application approval) gets a draft contract
        // for free — never blocks tenancy creation, e.g. before the landlord
        // has configured any contract template yet.
        try {
            app(ContractService::class)->generateForTenancy($tenancy);
        } catch (\RuntimeException $e) {
            Log::warning('Skipped auto-generating contract: '.$e->getMessage(), ['tenancy_id' => $tenancy->id]);
        }
    }

    public function updated(Tenancy $tenancy): void
    {
        if (! $tenancy->wasChanged('status')) {
            return;
        }

        if (in_array($tenancy->status, Tenancy::OCCUPYING_STATUSES, true)) {
            $tenancy->house->update(['status' => 'occupied']);

            return;
        }

        $stillOccupied = $tenancy->house->tenancies()->active()->exists();
        if (! $stillOccupied) {
            $tenancy->house->update(['status' => 'vacant']);
        }
    }
}
