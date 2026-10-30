<?php

namespace App\Livewire\Tenant;

use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Profile extends Component
{
    public string $phone = '';

    public string $email = '';

    public string $emergency_contact_name = '';

    public string $emergency_contact_phone = '';

    public function mount(): void
    {
        $tenant = Auth::user()->tenant;
        abort_if(! $tenant, 404);

        $this->phone = $tenant->phone;
        $this->email = (string) $tenant->email;
        $this->emergency_contact_name = (string) $tenant->emergency_contact_name;
        $this->emergency_contact_phone = (string) $tenant->emergency_contact_phone;
    }

    public function save(): void
    {
        $tenant = Auth::user()->tenant;
        abort_if(! $tenant, 404);

        $data = $this->validate([
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $tenant->update($data);

        $activeTenancy = $tenant->currentTenancy();
        $activeTenancy?->load('house.block.property');
        if ($activeTenancy) {
            app(NotificationService::class)->notify(
                $activeTenancy->house->block->property->landlord,
                'tenant_profile_updated',
                'Tenant Updated Their Contact Details',
                "{$tenant->name} ({$activeTenancy->house->house_number}) updated their profile information.",
                ['in_app'],
                $tenant,
            );
        }

        session()->flash('status', 'Profile updated.');
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        $tenancy?->load('house.block.property');

        return view('livewire.tenant.profile', [
            'tenant' => $tenant,
            'tenancy' => $tenancy,
        ]);
    }
}
