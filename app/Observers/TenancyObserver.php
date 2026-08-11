<?php

namespace App\Observers;

use App\Models\Tenancy;

class TenancyObserver
{
    public function created(Tenancy $tenancy): void
    {
        if (in_array($tenancy->status, Tenancy::OCCUPYING_STATUSES, true)) {
            $tenancy->house->update(['status' => 'occupied']);
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
