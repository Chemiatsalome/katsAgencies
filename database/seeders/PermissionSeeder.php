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
            // Granted per-manager (directly to that manager's User record, not
            // via the role) by the landlord when delegating access — see
            // Manager\Form and User::hasManagerPermission(). Landlords never
            // need these granted to their own role; they're never
            // permission-restricted, only ever scoped to their own data.
            'Manager Delegation' => self::managerPermissions(),
        ];
    }

    /** @return string[] */
    public static function managerPermissions(): array
    {
        return collect(static::managerPermissionGroups())->flatten()->all();
    }

    /**
     * The same permission set as managerPermissions(), grouped for display on
     * the manager invitation/edit checkbox UI (Managers\Form).
     *
     * @return array<string, array<int, string>>
     */
    public static function managerPermissionGroups(): array
    {
        return [
            'Tenants' => ['tenants.view', 'tenants.create', 'tenants.edit'],
            // 'manage_contracts' is deliberately separate from 'terminate' —
            // they used to be the same checkbox (ContractPolicy gated
            // create/update on tenancies.terminate, since no dedicated
            // permission existed), which meant granting termination rights
            // silently also granted contract management. Decoupled here;
            // see the migration that retroactively grants this to any
            // manager who already had 'terminate', so nobody's real-world
            // access regresses from this split.
            'Tenancies' => ['tenancies.view', 'tenancies.create', 'tenancies.terminate', 'tenancies.manage_contracts'],
            'Maintenance' => ['maintenance.view', 'maintenance.create', 'maintenance.schedule', 'maintenance.assign', 'maintenance.resolve'],
            // 'resolve' is deliberately not a separate permission here —
            // resolving a complaint IS completing its linked maintenance
            // task (Maintenance\Index::complete()), which is already gated
            // by maintenance.resolve. A standalone complaints.resolve used
            // to exist but was checked nowhere in the app; keeping it would
            // have landlords toggling a checkbox that did nothing.
            'Complaints' => ['complaints.view', 'complaints.respond'],
            'Applications' => ['applications.view', 'applications.review', 'applications.approve'],
            'Properties' => ['properties.create', 'properties.edit'],
            // Deliberately the only finance permission on offer — Expenses,
            // Income, Finance Overview, Reports, Deposits, Vendors, and
            // Analytics stay landlord-only and are never delegable, matching
            // the module's "financial access restricted by default" design.
            // This is the sole named carve-out.
            'Finance' => ['finance.view_rent_status'],
        ];
    }

    /** Human-readable labels for the checkbox UI — falls back to the raw permission string if not listed. */
    public static function permissionLabels(): array
    {
        return [
            'tenants.view' => 'View tenants',
            'tenants.create' => 'Add tenants',
            'tenants.edit' => 'Edit tenant details',
            'tenancies.view' => 'View tenancies',
            'tenancies.create' => 'Create tenancies',
            'tenancies.terminate' => 'Terminate / end tenancies',
            'tenancies.manage_contracts' => 'Generate, send, and upload tenancy contracts',
            'maintenance.view' => 'View maintenance tasks',
            'maintenance.create' => 'Log maintenance requests',
            'maintenance.schedule' => 'Schedule maintenance',
            'maintenance.assign' => 'Assign vendors',
            'maintenance.resolve' => 'Mark maintenance resolved',
            'complaints.view' => 'View complaints',
            'complaints.respond' => 'Respond to complaints',
            'applications.view' => 'View applications',
            'applications.review' => 'Review applications',
            'applications.approve' => 'Approve / reject applications',
            'properties.create' => 'Add properties',
            'properties.edit' => 'Edit property/unit details',
            'finance.view_rent_status' => 'View rent status (who has paid / is owing)',
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
