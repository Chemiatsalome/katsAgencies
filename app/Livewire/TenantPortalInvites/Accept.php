<?php

namespace App\Livewire\TenantPortalInvites;

use App\Models\Tenant;
use App\Services\TenancyProvisioningService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Accept extends Component
{
    public Tenant $tenant;

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->tenant = Tenant::where('portal_invite_token', $token)->firstOrFail();
        $this->email = $this->tenant->email ?? '';
    }

    public function register(): void
    {
        $this->tenant->refresh();

        if ($this->tenant->hasPortalAccess()) {
            $this->addError('password', 'This account has already been set up — please log in instead.');

            return;
        }

        $this->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user = app(TenancyProvisioningService::class)->createPortalAccount(
            $this->tenant,
            $this->tenant->name,
            $this->email,
            $this->password,
        );

        $this->tenant->update([
            'email' => $this->email,
            'portal_invite_token' => null,
        ]);

        Auth::login($user);

        $this->redirectRoute('tenant.dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.tenant-portal-invites.accept');
    }
}
