<?php

namespace Database\Seeders;

use Database\Seeders\Portfolios\MbungoniBombululuSeeder;
use Database\Seeders\Portfolios\MontheApartmentShanziSeeder;
use Database\Seeders\Portfolios\MtopangaApartmentSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            UserSeeder::class,
            FinanceCategorySeeder::class,
            MbungoniBombululuSeeder::class,
            MontheApartmentShanziSeeder::class,
            MtopangaApartmentSeeder::class,
            TenantPortalDemoSeeder::class,
        ]);
    }
}
