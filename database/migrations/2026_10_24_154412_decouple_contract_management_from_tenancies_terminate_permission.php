<?php

use App\Models\Manager;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * ContractPolicy and the contract actions on Tenancies\Show/Contracts\Index
 * used to gate on tenancies.terminate — no dedicated contracts permission
 * existed, so granting termination rights silently also granted contract
 * management. Decoupled in code; this data migration grants every manager
 * who already has tenancies.terminate the new tenancies.manage_contracts
 * permission too, so nobody's real-world access regresses from the split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'tenancies.manage_contracts']);

        Manager::whereNotNull('user_id')->get()->each(function (Manager $manager) {
            $permissions = $manager->permissions ?? [];

            if (! in_array('tenancies.terminate', $permissions, true)) {
                return;
            }

            if (! in_array('tenancies.manage_contracts', $permissions, true)) {
                $manager->update(['permissions' => [...$permissions, 'tenancies.manage_contracts']]);
            }

            $manager->user?->givePermissionTo('tenancies.manage_contracts');
        });
    }

    public function down(): void
    {
        Manager::whereNotNull('user_id')->get()->each(function (Manager $manager) {
            $permissions = array_values(array_diff($manager->permissions ?? [], ['tenancies.manage_contracts']));
            $manager->update(['permissions' => $permissions]);
            $manager->user?->revokePermissionTo('tenancies.manage_contracts');
        });
    }
};
