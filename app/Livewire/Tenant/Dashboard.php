<?php

namespace App\Livewire\Tenant;

use App\Models\RentCharge;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Dashboard extends Component
{
    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->tenancies()->active()->with('house.block.property')->first();

        $nextCharge = $tenancy
            ? RentCharge::where('tenancy_id', $tenancy->id)->outstanding()->orderBy('due_date')->first()
            : null;

        return view('livewire.tenant.dashboard', [
            'tenant' => $tenant,
            'tenancy' => $tenancy,
            'balance' => $tenancy?->balance() ?? 0,
            'depositBalance' => $tenancy?->depositBalance() ?? 0,
            'nextCharge' => $nextCharge,
            'recentPayments' => $tenancy
                ? $tenancy->payments()->latest('payment_date')->latest('id')->limit(5)->get()
                : collect(),
        ]);
    }
}
