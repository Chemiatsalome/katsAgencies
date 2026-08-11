<?php

namespace Database\Seeders\Portfolios;

use App\Models\Property;

class MontheApartmentShanziSeeder extends PortfolioSeeder
{
    public function run(): void
    {
        $property = $this->property('Monthe Apartment', 'Shanzu, Mombasa');

        $this->blockTop($property);
        $this->blockBottom($property);
    }

    private function blockTop(Property $property): void
    {
        $block = $this->block($property, 'Top');

        // House 1 — Phenny (One Bedroom)
        $house = $this->house($block, '1', 'one_bedroom', 25000, 25000);
        $t = $this->moveIn(
            $house,
            ['name' => 'Phenny', 'phone' => '0745705227'],
            '2024-01-01',
            25000,
            25000,
            'Unresolved historical annotation carried over verbatim: "Move in 5th Dec/24" — may describe a later tenancy change on this house; please review.'
        );
        $this->applySchedule($t, array_fill_keys(range(1, 7), ['paid' => 25000]));

        // House 2 — Elkana (One Bedroom)
        $house = $this->house($block, '2', 'one_bedroom', 25000, 25000);
        $t = $this->moveIn(
            $house,
            ['name' => 'Elkana', 'phone' => '0780011359'],
            '2024-01-01',
            25000,
            25000,
            'Unresolved historical annotation carried over verbatim: "Move in, Move in Nov/25" — may describe a later tenancy change on this house; please review.'
        );
        $this->applySchedule($t, array_fill_keys(range(1, 7), ['paid' => 25000]));

        // House 3 (Two Bedroom, prospective tenant "Nicholas") — no rent figure or payment
        // data recorded in the historical sheet, so no house/tenancy record is seeded here.
        // Add it manually once the rent amount is confirmed.
    }

    private function blockBottom(Property $property): void
    {
        $block = $this->block($property, 'Bottom');

        // House 4 — Mhindi (Main House)
        $house = $this->house($block, '4', 'house', 38000, 38000);
        $t = $this->moveIn($house, ['name' => 'Mhindi', 'phone' => '0713034854'], '2024-01-01', 38000, 38000);
        $this->applySchedule($t, array_fill_keys(range(1, 8), ['paid' => 38000]));

        // House 5 — Bedsitter 1 (Siphrosa)
        $house = $this->house($block, '5', 'bedsitter', 14000, 14000);
        $t = $this->moveIn($house, ['name' => 'Siphrosa', 'phone' => '0724735860'], '2024-01-01', 14000, 14000);
        $this->applySchedule($t, array_fill_keys(range(1, 7), ['paid' => 14000]));

        // House 6 (Bedsitter) and House 7 (Bedsitter 3, "Janet") have no rent figure or
        // payment data recorded in the historical sheet, so no records are seeded here.
        // Add them manually once rent amounts are confirmed.
    }
}
