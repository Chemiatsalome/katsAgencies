<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Gives one already-seeded tenant a portal login, so the tenant module has
 * real demo data to sign into immediately after seeding.
 */
class TenantPortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::whereNull('user_id')
            ->whereHas('tenancies', fn ($q) => $q->active())
            ->orderBy('id')
            ->first();

        if (! $tenant) {
            return;
        }

        $user = User::firstOrCreate(
            ['email' => 'tenant@katsagencies.test'],
            [
                'name' => $tenant->name,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $user->syncRoles(['tenant']);

        $tenant->update(['user_id' => $user->id]);
    }
}
