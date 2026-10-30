<?php

namespace App\Livewire\TenantInvitations;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Services\NotificationService;
use App\Services\TenancyProvisioningService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Accept extends Component
{
    public TenantInvitation $invitation;

    public bool $wantsToLogIn = false;

    // New-account registration fields
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $id_number = '';

    public string $emergency_contact_name = '';

    public string $emergency_contact_phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    // Existing-account login fields
    public string $login_email = '';

    public string $login_password = '';

    public function mount(string $token): void
    {
        $this->invitation = TenantInvitation::with('house.block.property', 'existingTenant')
            ->where('token', $token)
            ->firstOrFail();

        $this->invitation->markOpened();
    }

    public function switchToLogin(): void
    {
        $this->wantsToLogIn = true;
        $this->resetErrorBag();
    }

    public function switchToRegister(): void
    {
        $this->wantsToLogIn = false;
        $this->resetErrorBag();
    }

    public function acceptAsCurrentTenant(): void
    {
        if (! $this->guardUsable()) {
            return;
        }

        $user = Auth::user();

        if (! $user || ! $user->hasRole('tenant') || ! $user->tenant) {
            return;
        }

        $this->completeWithTenant($user->tenant);
    }

    public function logInAndAccept(): void
    {
        if (! $this->guardUsable()) {
            return;
        }

        $this->validate([
            'login_email' => ['required', 'email'],
            'login_password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => $this->login_email, 'password' => $this->login_password])) {
            $this->addError('login_email', 'Those credentials do not match an existing account.');

            return;
        }

        $user = Auth::user();

        if (! $user->hasRole('tenant') || ! $user->tenant) {
            Auth::logout();
            $this->addError('login_email', 'This account is not a tenant account.');

            return;
        }

        $this->completeWithTenant($user->tenant);
    }

    public function register(): void
    {
        if (! $this->guardUsable()) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'id_number' => ['nullable', 'string', 'max:64'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $provisioning = app(TenancyProvisioningService::class);

        $tenant = Tenant::create([
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'id_number' => $this->id_number ?: null,
            'emergency_contact_name' => $this->emergency_contact_name ?: null,
            'emergency_contact_phone' => $this->emergency_contact_phone ?: null,
            'payment_due_day' => $this->invitation->rent_due_day,
        ]);

        $user = $provisioning->createPortalAccount($tenant, $this->name, $this->email, $this->password);

        Auth::login($user);

        $this->completeWithTenant($tenant->fresh());
    }

    private function guardUsable(): bool
    {
        $this->invitation->refresh();

        if (! $this->invitation->isUsable()) {
            $this->addError('name', 'This invitation is no longer available.');

            return false;
        }

        return true;
    }

    private function completeWithTenant(Tenant $tenant): void
    {
        if (! $this->guardUsable()) {
            return;
        }

        $provisioning = app(TenancyProvisioningService::class);

        $tenancy = $provisioning->createTenancyForExistingTenant($tenant, [
            'house_id' => $this->invitation->house_id,
            'move_in_date' => $this->invitation->move_in_date,
            'lease_end_date' => $this->invitation->lease_end_date,
            'notice_period_days' => $this->invitation->notice_period_days,
            'monthly_rent' => $this->invitation->monthly_rent,
            'deposit_required' => $this->invitation->deposit_required,
            'rent_due_day' => $this->invitation->rent_due_day,
            'status' => 'active',
        ]);

        $this->invitation->update([
            'status' => 'completed',
            'completed_at' => now(),
            'resulting_tenancy_id' => $tenancy->id,
        ]);

        app(NotificationService::class)->notify(
            $this->invitation->invitedBy,
            'tenant_registered',
            'New Tenant Registered',
            "{$tenant->name} completed registration for {$this->invitation->house->block->property->name} — {$this->invitation->house->house_number}.",
            ['in_app'],
            $tenancy,
        );

        $this->redirectRoute('tenant.dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.tenant-invitations.accept');
    }
}
