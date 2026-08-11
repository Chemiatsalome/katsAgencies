<?php

namespace App\Livewire\Tenants;

use App\Models\Tenant;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Show extends Component
{
    public Tenant $tenant;

    #[Url]
    public string $tab = 'overview';

    public function mount(Tenant $tenant): void
    {
        $this->authorize('view', $tenant);
        $this->tenant = $tenant;
    }

    public function render()
    {
        $currentTenancy = $this->tenant->tenancies()->active()->with('house.block.property')->first();

        $data = ['currentTenancy' => $currentTenancy];

        match ($this->tab) {
            'payments' => $data['payments'] = \App\Models\Payment::whereIn('tenancy_id', $this->tenant->tenancies()->pluck('id'))->latest('payment_date')->limit(50)->get(),
            'arrears' => $data['rentCharges'] = \App\Models\RentCharge::whereIn('tenancy_id', $this->tenant->tenancies()->pluck('id'))->outstanding()->with('tenancy.house')->get(),
            'complaints' => $data['complaints'] = $this->tenant->complaints()->latest()->get(),
            'documents' => null,
            'activity' => $data['tenancies'] = $this->tenant->tenancies()->with('house.block.property')->latest()->get(),
            default => null,
        };

        return view('livewire.tenants.show', $data);
    }
}
