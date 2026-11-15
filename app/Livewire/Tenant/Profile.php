<?php

namespace App\Livewire\Tenant;

use App\Rules\ValidPhoneNumber;
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

        // Kenya-only, same as every other direct tenant/landlord phone
        // entry point (Tenants\Form) — Tenant has no per-record
        // country_code the way User does, and this app has no tenants
        // outside Kenya today.
        $data = $this->validate([
            'phone' => ['required', 'string', 'max:32', new ValidPhoneNumber('KE')],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        // Phase 9 audit: only ping the landlord when an operational
        // contact detail actually changed — resubmitting the same form
        // must not notify them again.
        $tenant->fill($data);
        $contactDetailsChanged = $tenant->isDirty(['phone', 'email', 'emergency_contact_name', 'emergency_contact_phone']);
        $tenant->save();

        if ($contactDetailsChanged) {
            $activeTenancy = $tenant->currentTenancy();
            $activeTenancy?->load('house.block.property');
            if ($activeTenancy) {
                app(NotificationService::class)->notifyPropertyStakeholders(
                    $activeTenancy->house->block->property,
                    'tenant_profile_updated',
                    'Tenant Updated Their Contact Details',
                    "{$tenant->name} ({$activeTenancy->house->house_number}) updated their profile information.",
                    ['in_app'],
                    $tenant,
                    'tenants.view',
                );
            }
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
