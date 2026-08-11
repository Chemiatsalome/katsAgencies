<?php

namespace App\Livewire\Tenant;

use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant')]
class MyTenancy extends Component
{
    public bool $showRequestChangeForm = false;

    public string $requestMessage = '';

    public bool $showRenewalForm = false;

    public string $renewalMessage = '';

    public function requestChange(): void
    {
        $this->validate(['requestMessage' => ['required', 'string', 'max:1000']]);

        $tenancy = Auth::user()->tenant?->tenancies()->active()->with('house.block.property')->first();
        abort_if(! $tenancy, 404);

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'tenancy_change_request',
            'Tenant Requested a Tenancy Detail Change',
            "{$tenancy->tenant->name} ({$tenancy->house->house_number}) says: {$this->requestMessage}",
            ['in_app'],
            $tenancy,
        );

        $this->reset(['requestMessage', 'showRequestChangeForm']);
        session()->flash('status', 'Your request has been sent to your landlord.');
    }

    public function requestRenewal(): void
    {
        $this->validate(['renewalMessage' => ['nullable', 'string', 'max:1000']]);

        $tenancy = Auth::user()->tenant?->tenancies()->active()->with('house.block.property')->first();
        abort_if(! $tenancy, 404);

        $body = $this->renewalMessage !== ''
            ? "{$tenancy->tenant->name} ({$tenancy->house->house_number}) is asking about renewal: {$this->renewalMessage}"
            : "{$tenancy->tenant->name} ({$tenancy->house->house_number}) would like to discuss renewing their tenancy.";

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'renewal_request',
            'Tenant Interested in Renewal',
            $body,
            ['in_app'],
            $tenancy,
        );

        $this->reset(['renewalMessage', 'showRenewalForm']);
        session()->flash('status', 'Your renewal request has been sent to your landlord.');
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $currentTenancy = $tenant?->tenancies()->active()->with('house.block.property')->latest('move_in_date')->first();

        $pastTenancies = $tenant
            ? $tenant->tenancies()->with('house.block.property')
                ->when($currentTenancy, fn ($q) => $q->where('id', '!=', $currentTenancy->id))
                ->orderByDesc('move_in_date')
                ->get()
            : collect();

        return view('livewire.tenant.my-tenancy', [
            'tenancy' => $currentTenancy,
            'pastTenancies' => $pastTenancies,
        ]);
    }
}
