<?php

namespace App\Livewire\Tenant;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Rent extends Component
{
    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= (int) now()->year;
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->tenancies()->with('house.block.property')->latest('move_in_date')->first();

        $availableYears = $tenancy
            ? range((int) $tenancy->move_in_date->year, max((int) now()->year, (int) $tenancy->move_in_date->year))
            : [(int) now()->year];

        return view('livewire.tenant.rent', [
            'tenancy' => $tenancy,
            'availableYears' => array_reverse($availableYears),
            'rentCharges' => $tenancy
                ? $tenancy->rentCharges()->where('period_year', $this->year)->orderByDesc('period_month')->get()
                : collect(),
            'payments' => $tenancy
                ? $tenancy->payments()->latest('payment_date')->latest('id')->get()
                : collect(),
        ]);
    }
}
