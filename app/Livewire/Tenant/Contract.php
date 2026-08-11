<?php

namespace App\Livewire\Tenant;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Contract extends Component
{
    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->tenancies()->with('house.block.property')->active()->latest('move_in_date')->first();

        $documents = $tenancy
            ? $tenancy->documents()->where('category', 'lease_agreement')->latest()->get()
            : collect();

        return view('livewire.tenant.contract', [
            'tenancy' => $tenancy,
            'documents' => $documents,
        ]);
    }
}
