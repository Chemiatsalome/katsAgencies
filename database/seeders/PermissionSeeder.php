<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * All permissions in the system, grouped for display on the Permissions matrix page.
     *
     * @return array<string, array<int, string>>
     */
    public static function groups(): array
    {
        return [
            'Property Management' => [
                'landlords.manage', 'properties.manage', 'units.manage',
                'tenants.manage', 'tenancies.manage', 'applications.manage',
            ],
            'Finance' => [
                'rent.manage', 'arrears.manage', 'deposits.manage', 'expenses.manage', 'finance.view',
            ],
            'Operations' => [
                'complaints.manage', 'documents.manage', 'notifications.manage',
            ],
            'Reporting' => [
                'reports.view', 'audit.view',
            ],
            'System' => [
                'users.manage', 'permissions.manage', 'settings.manage',
            ],
        ];
    }

    /** Permissions granted to the landlord role by default. */
    public static function landlordDefaults(): array
    {
        return [
            'properties.manage', 'units.manage', 'tenants.manage', 'tenancies.manage',
            'applications.manage', 'rent.manage', 'arrears.manage', 'deposits.manage', 'finance.view',
        ];
    }

    public function run(): void
    {
        $all = collect(static::groups())->flatten();

        $all->each(fn (string $permission) => Permission::firstOrCreate(['name' => $permission]));

        $admin = Role::where('name', 'admin')->first();
        $admin?->syncPermissions($all->all());

        $landlord = Role::where('name', 'landlord')->first();
        $landlord?->syncPermissions(static::landlordDefaults());
    }
}
