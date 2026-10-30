<?php

namespace App\Services;

use App\Models\Block;
use App\Models\House;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * The single place the onboarding wizard creates a Property and everything
 * inside it (Blocks, Houses) in one atomic operation — mirrors
 * TenancyProvisioningService's role as "the one place a [thing] gets
 * created" for its own part of the hierarchy.
 *
 * Touches only Property/Block/House, never Tenancy or DepositPayment: a
 * unit's deposit_amount here is only ever the amount *required*, decided
 * entirely by the wizard before this runs — actual payments stay the
 * exclusive job of TenancyProvisioningService/DepositService, triggered
 * later when a tenant is actually added.
 */
class PropertyProvisioningService
{
    /**
     * @param  array  $propertyData  landlord_id, name, type, address, latitude, longitude, photo_path (nullable)
     * @param  string  $layout  'standalone' | 'multiple_similar' | 'blocks' — 'standalone'/'multiple_similar'
     *                          both get one implicit "Main" block; 'blocks' gets $blockCount auto-named ones.
     * @param  int  $blockCount  only meaningful when $layout === 'blocks'
     * @param  array  $reviewUnits  flattened per-house rows, each carrying a 0-based 'block_index' into
     *                              the block list this method creates (in the same order every time —
     *                              one "Main" block, or blocks 0..$blockCount-1 for the 'blocks' layout)
     */
    public function provision(array $propertyData, string $layout, int $blockCount, array $reviewUnits): Property
    {
        return DB::transaction(function () use ($propertyData, $layout, $blockCount, $reviewUnits) {
            $property = Property::create($propertyData + ['status' => 'active']);

            $blocks = $layout === 'blocks'
                ? $this->createNamedBlocks($property, $blockCount)
                : [$property->blocks()->create(['name' => 'Main'])];

            foreach ($reviewUnits as $unit) {
                $block = $blocks[$unit['block_index']] ?? $blocks[0];

                $block->houses()->create([
                    'house_number' => $unit['house_number'],
                    'type' => $unit['type'],
                    'bedrooms' => $unit['bedrooms'] ?: null,
                    'monthly_rent' => $unit['monthly_rent'],
                    'deposit_amount' => $unit['deposit_amount'],
                    'electricity_arrangement' => $unit['electricity_arrangement'],
                    'water_arrangement' => $unit['water_arrangement'],
                    // Always vacant regardless of anything the wizard state could
                    // carry — these are brand-new units, never anyone's home yet.
                    'status' => 'vacant',
                    'description' => $unit['description'] ?? null,
                ]);
            }

            return $property->load('blocks.houses');
        });
    }

    /** @return Block[] */
    private function createNamedBlocks(Property $property, int $count): array
    {
        $blocks = [];

        for ($i = 0; $i < $count; $i++) {
            $blocks[] = $property->blocks()->create(['name' => 'Block '.$this->blockLetter($i)]);
        }

        return $blocks;
    }

    /** 0-based index -> A, B, ... Z, AA, AB, ... (bijective base-26, same scheme spreadsheet columns use). */
    private function blockLetter(int $index): string
    {
        $letters = '';

        do {
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $letters;
    }
}
