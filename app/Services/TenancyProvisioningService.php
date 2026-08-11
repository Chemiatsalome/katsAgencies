<?php

namespace App\Services;

use App\Models\DepositPayment;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The single place a Tenancy (and, where needed, its Tenant) gets created —
 * shared by manual "Add Tenant", invitation self-registration, and bulk
 * import, so all three produce the exact same tenant/tenancy shape.
 */
class TenancyProvisioningService
{
    /**
     * Create a Tenancy for an existing Tenant profile — used when a tenant
     * already has an account (or a landlord picks an existing tenant record)
     * and is simply moving into a new/second unit. Preserves tenancy history.
     */
    public function createTenancyForExistingTenant(Tenant $tenant, array $tenancyData): Tenancy
    {
        return $this->createTenancy($tenant, $tenancyData);
    }

    /**
     * Create a brand-new Tenant profile plus its first Tenancy in one step.
     */
    public function createTenantAndTenancy(array $tenantData, array $tenancyData): Tenancy
    {
        $tenant = Tenant::create($tenantData);

        return $this->createTenancy($tenant, $tenancyData);
    }

    /**
     * Attach portal login credentials to a Tenant record — the same
     * User+role+link sequence the landlord's manual "Add Tenant" form uses.
     */
    public function createPortalAccount(Tenant $tenant, string $name, string $email, string $password): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant']);
        $tenant->update(['user_id' => $user->id]);

        return $user;
    }

    private function createTenancy(Tenant $tenant, array $data): Tenancy
    {
        $tenancy = Tenancy::create([
            'tenant_id' => $tenant->id,
            'house_id' => $data['house_id'],
            'move_in_date' => $data['move_in_date'],
            'lease_end_date' => $data['lease_end_date'] ?? null,
            'notice_period_days' => $data['notice_period_days'] ?? null,
            'monthly_rent' => $data['monthly_rent'],
            'deposit_required' => $data['deposit_required'] ?? 0,
            'rent_due_day' => $data['rent_due_day'],
            'status' => $data['status'] ?? 'active',
        ]);

        if (! empty($data['initial_deposit_paid']) && (float) $data['initial_deposit_paid'] > 0) {
            DepositPayment::create([
                'tenancy_id' => $tenancy->id,
                'amount' => $data['initial_deposit_paid'],
                'payment_date' => $data['move_in_date'],
                'method' => $data['deposit_method'] ?? 'cash',
                'recorded_by' => $data['recorded_by'] ?? Auth::id(),
            ]);
        }

        return $tenancy;
    }
}
