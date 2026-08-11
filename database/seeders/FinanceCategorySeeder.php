<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use Illuminate\Database\Seeder;

class FinanceCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Maintenance', 'Utilities', 'Cleaning', 'Security',
            'Property Tax', 'Insurance', 'Management Fee', 'Landscaping', 'Other',
        ] as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        foreach ([
            'Parking Fee', 'Late Payment Fee', 'Amenity Fee', 'Other',
        ] as $name) {
            IncomeCategory::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
