<?php

namespace App\Livewire\Admin;

use App\Models\House;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $query = '';

    public function render()
    {
        $results = [];
        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');

        if (mb_strlen($this->query) >= 2) {
            $q = $this->query;

            if ($isAdmin) {
                // Admin oversees landlords and the platform, not individual tenants/rent — search is scoped accordingly.
                $results = [
                    'Landlords' => User::role('landlord')->where(fn ($qq) => $qq->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))->limit(5)->get(['id', 'name', 'email']),
                    'Properties' => Property::where('name', 'like', "%{$q}%")->with('landlord')->limit(5)->get(),
                ];
            } else {
                $properties = Property::where('name', 'like', "%{$q}%")->forLandlord($user->id)->limit(5)->get(['id', 'name']);

                $houses = House::where('house_number', 'like', "%{$q}%")
                    ->whereHas('block.property', fn ($qq) => $qq->where('landlord_id', $user->id))
                    ->with('block.property')->limit(5)->get();

                $tenants = Tenant::where(fn ($qq) => $qq->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
                    ->whereHas('tenancies.house.block.property', fn ($qq) => $qq->where('landlord_id', $user->id))
                    ->limit(5)->get();

                $tenancies = is_numeric($q)
                    ? Tenancy::where('id', $q)->whereHas('house.block.property', fn ($qq) => $qq->where('landlord_id', $user->id))->with('tenant')->limit(5)->get()
                    : collect();

                $payments = Payment::where('reference_number', 'like', "%{$q}%")
                    ->whereHas('tenancy.house.block.property', fn ($qq) => $qq->where('landlord_id', $user->id))
                    ->with('tenancy.tenant')->limit(5)->get();

                $results = [
                    'Properties' => $properties,
                    'Units' => $houses,
                    'Tenants' => $tenants,
                    'Tenancies' => $tenancies,
                    'Payments' => $payments,
                ];
            }

            $results = array_filter($results, fn ($group) => $group->isNotEmpty());
        }

        return view('livewire.admin.global-search', ['results' => $results, 'isAdmin' => $isAdmin]);
    }
}
