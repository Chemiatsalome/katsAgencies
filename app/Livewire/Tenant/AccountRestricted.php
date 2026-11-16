<?php

namespace App\Livewire\Tenant;

use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Final account-lifecycle phase, Part A1: the one page
 * EnsureTenantLandlordIsActive still lets a restricted tenant reach.
 * Explains the restriction and provides a way to contact Kats Agencies
 * administration — logout is available via the normal layout's own
 * sidebar, rendered as part of this same page.
 */
#[Layout('layouts.tenant')]
class AccountRestricted extends Component
{
    public function render()
    {
        return view('livewire.tenant.account-restricted', [
            // Same platform-configured administrator contact used throughout
            // this phase's notifications — see LandlordSuspensionService.
            'contactEmail' => Setting::get('org_contact_email'),
            'landlordName' => Auth::user()->tenant?->currentTenancy()?->house->block->property->landlord->name,
        ]);
    }
}
