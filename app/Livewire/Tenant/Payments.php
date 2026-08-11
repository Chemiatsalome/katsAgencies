<?php

namespace App\Livewire\Tenant;

use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant')]
class Payments extends Component
{
    use WithPagination;

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancyIds = $tenant ? $tenant->tenancies()->pluck('id') : collect();

        $payments = Payment::whereIn('tenancy_id', $tenancyIds)
            ->with('rentCharge')
            ->latest('payment_date')
            ->latest('id')
            ->paginate(20);

        return view('livewire.tenant.payments', [
            'payments' => $payments,
        ]);
    }
}
