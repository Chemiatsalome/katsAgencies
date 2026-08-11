<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@katsagencies.test'],
            [
                'name' => 'Kats Agencies Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $admin->syncRoles(['admin']);

        $landlord = User::firstOrCreate(
            ['email' => 'landlord@katsagencies.test'],
            [
                'name' => 'Kats Agencies Landlord',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $landlord->syncRoles(['landlord']);
    }
}
