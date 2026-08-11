<?php

namespace Database\Seeders\Portfolios;

class MtopangaApartmentSeeder extends PortfolioSeeder
{
    public function run(): void
    {
        $property = $this->property('Mtopanga Apartment', 'Mtopanga, Mombasa');
        $block = $this->block($property, 'Top');

        // House 1 — Timothy
        $house = $this->house($block, '1', 'other', 18000, 18000);
        $t = $this->moveIn($house, ['name' => 'Timothy', 'phone' => '0714753237'], '2024-01-01', 18000, 18000);

        $schedule = array_fill_keys(range(1, 6), ['paid' => 18000]);
        $schedule[7] = ['note' => 'Sheet recorded "Electricity bill (25)" instead of a rent payment this month — rent left outstanding rather than marked paid.'];
        $schedule[8] = ['note' => 'Sheet recorded "Electricity bill (25)" instead of a rent payment this month — rent left outstanding rather than marked paid.'];
        $this->applySchedule($t, $schedule);
    }
}
