<?php

namespace App\Livewire\Properties;

use App\Models\Block;
use App\Models\House;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
class Import extends Component
{
    use WithFileUploads;

    public const UNIT_TYPE_ALIASES = [
        'bedsitter' => 'bedsitter',
        'studio' => 'studio',
        'sq' => 'sq',
        'servant quarter' => 'sq',
        'servant quarters' => 'sq',
        '1br' => 'one_bedroom',
        '1 br' => 'one_bedroom',
        'one bedroom' => 'one_bedroom',
        '1 bedroom' => 'one_bedroom',
        '2br' => 'two_bedroom',
        '2 br' => 'two_bedroom',
        'two bedroom' => 'two_bedroom',
        '2 bedroom' => 'two_bedroom',
        '3br' => 'three_bedroom',
        '3 br' => 'three_bedroom',
        'three bedroom' => 'three_bedroom',
        '3 bedroom' => 'three_bedroom',
        '4br' => 'four_bedroom',
        '4 br' => 'four_bedroom',
        'four bedroom' => 'four_bedroom',
        '4 bedroom' => 'four_bedroom',
        'house' => 'house',
        'maisonette' => 'maisonette',
        'shop' => 'shop',
        'office' => 'office',
        'other' => 'other',
        '' => 'bedsitter', // matches Houses\Form's own default when left blank
    ];

    public const PROPERTY_TYPE_ALIASES = [
        'apartment' => 'apartment',
        'residential compound' => 'residential_compound',
        'compound' => 'residential_compound',
        'individual house' => 'individual_house',
        'maisonette' => 'maisonette',
        'commercial building' => 'commercial_building',
        'commercial' => 'commercial_building',
        'mixed use' => 'mixed_use',
        'mixed' => 'mixed_use',
        'other' => 'other',
        '' => 'other', // matches Properties\Form's own default when left blank
    ];

    public const ARRANGEMENT_ALIASES = [
        'shared' => 'shared',
        'separate' => 'separate_meter',
        'separate meter' => 'separate_meter',
        'own meter' => 'separate_meter',
        'prepaid' => 'separate_meter',
        'included' => 'included',
        'inclusive' => 'included',
    ];

    public $file;

    public array $rows = [];

    public bool $previewed = false;

    public bool $imported = false;

    public int $createdPropertiesCount = 0;

    public int $createdBlocksCount = 0;

    public int $createdHousesCount = 0;

    public function mount(): void
    {
        $this->authorize('create', Property::class);
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }

    public function preview(): void
    {
        $this->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ]);

        $landlordId = Auth::user()->landlordId();

        $existingProperties = Property::forLandlord($landlordId)->with('blocks.houses')->get();

        $handle = fopen($this->file->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header ?: []);

        // array_search() returns false (not null) when a column is absent — and
        // PHP coerces a false array key to 0, so without this explicit check a
        // missing optional column would silently read back column 0's value
        // instead of defaulting to ''.
        $col = fn (array $row, string $name) => trim((string) (($i = array_search($name, $header)) === false ? '' : ($row[$i] ?? '')));

        $seenInFile = [];
        $rows = [];

        while (($csvRow = fgetcsv($handle)) !== false) {
            if (count(array_filter($csvRow, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // skip blank lines
            }

            $propertyName = $col($csvRow, 'property');
            $propertyType = $col($csvRow, 'property type');
            $propertyAddress = $col($csvRow, 'property address');
            $blockName = $col($csvRow, 'block');
            $unitNumber = $col($csvRow, 'unit');
            $unitType = $col($csvRow, 'unit type');
            $bedrooms = $col($csvRow, 'bedrooms');
            $rent = $col($csvRow, 'rent');
            $deposit = $col($csvRow, 'deposit');
            $electricity = $col($csvRow, 'electricity');
            $water = $col($csvRow, 'water');

            $row = [
                'property' => $propertyName,
                'property_type' => $propertyType,
                'property_address' => $propertyAddress,
                'block' => $blockName,
                'unit' => $unitNumber,
                'unit_type' => $unitType,
                'bedrooms' => $bedrooms,
                'rent' => $rent,
                'deposit' => $deposit,
                'electricity' => $electricity,
                'water' => $water,
                'bucket' => null,
                'reason' => null,
            ];

            if ($propertyName === '' || $blockName === '' || $unitNumber === '' || $rent === '' || ! is_numeric($rent) || $deposit === '' || ! is_numeric($deposit)) {
                $row['bucket'] = 'invalid';
                $row['reason'] = 'Missing or invalid required field (property, block, unit, rent, deposit).';
                $rows[] = $row;

                continue;
            }

            $unitTypeKey = $this->normalizeKey($unitType);
            if (! array_key_exists($unitTypeKey, self::UNIT_TYPE_ALIASES)) {
                $row['bucket'] = 'invalid';
                $row['reason'] = "Unrecognized unit type \"{$unitType}\". Use e.g. Bedsitter, Studio, One Bedroom, Two Bedroom, House, Maisonette, Shop, Office.";
                $rows[] = $row;

                continue;
            }

            $propertyTypeKey = $this->normalizeKey($propertyType);
            if (! array_key_exists($propertyTypeKey, self::PROPERTY_TYPE_ALIASES)) {
                $row['bucket'] = 'invalid';
                $row['reason'] = "Unrecognized property type \"{$propertyType}\". Use e.g. Apartment, Residential Compound, Individual House, Commercial Building, Mixed Use, or leave blank.";
                $rows[] = $row;

                continue;
            }

            if ($bedrooms !== '' && (! is_numeric($bedrooms) || (int) $bedrooms < 0 || (int) $bedrooms > 20)) {
                $row['bucket'] = 'invalid';
                $row['reason'] = 'Bedrooms must be a whole number between 0 and 20, or left blank.';
                $rows[] = $row;

                continue;
            }

            foreach (['electricity' => $electricity, 'water' => $water] as $field => $value) {
                if ($value !== '' && ! array_key_exists($this->normalizeKey($value), self::ARRANGEMENT_ALIASES)) {
                    $row['bucket'] = 'invalid';
                    $row['reason'] = ucfirst($field)." arrangement \"{$value}\" not recognized. Use Shared, Separate Meter, or Included, or leave blank.";
                    $rows[] = $row;

                    continue 2;
                }
            }

            $dedupeKey = $this->normalizeKey($propertyName).'|'.$this->normalizeKey($blockName).'|'.$this->normalizeKey($unitNumber);

            if (isset($seenInFile[$dedupeKey])) {
                $row['bucket'] = 'duplicate';
                $row['reason'] = 'Same property/block/unit already appears earlier in this file.';
                $rows[] = $row;

                continue;
            }

            $existingProperty = $existingProperties->first(fn (Property $p) => strcasecmp($p->name, $propertyName) === 0);
            $existingBlock = $existingProperty?->blocks->first(fn (Block $b) => strcasecmp($b->name, $blockName) === 0);
            $existingHouse = $existingBlock?->houses->first(fn (House $h) => strcasecmp($h->house_number, $unitNumber) === 0);

            if ($existingHouse) {
                $row['bucket'] = 'duplicate';
                $row['reason'] = "Unit \"{$unitNumber}\" already exists under {$propertyName} / {$blockName}.";
                $rows[] = $row;

                continue;
            }

            $seenInFile[$dedupeKey] = true;
            $row['bucket'] = 'valid';
            $rows[] = $row;
        }

        fclose($handle);

        $this->rows = $rows;
        $this->previewed = true;
    }

    public function commit(): void
    {
        $this->authorize('create', Property::class);

        $landlordId = Auth::user()->landlordId();

        $properties = Property::forLandlord($landlordId)->with('blocks')->get()->keyBy(fn (Property $p) => $this->normalizeKey($p->name));
        $createdProperties = 0;
        $createdBlocks = 0;
        $createdHouses = 0;

        foreach ($this->rows as $row) {
            if ($row['bucket'] !== 'valid') {
                continue;
            }

            $propertyKey = $this->normalizeKey($row['property']);
            $property = $properties->get($propertyKey);

            if (! $property) {
                $property = Property::create([
                    'landlord_id' => $landlordId,
                    'name' => $row['property'],
                    'type' => self::PROPERTY_TYPE_ALIASES[$this->normalizeKey($row['property_type'])],
                    'address' => $row['property_address'] ?: null,
                    'status' => 'active',
                ]);
                $property->setRelation('blocks', collect());
                $properties->put($propertyKey, $property);
                $createdProperties++;
            }

            $blockKey = $this->normalizeKey($row['block']);
            $block = $property->blocks->first(fn (Block $b) => $this->normalizeKey($b->name) === $blockKey);

            if (! $block) {
                $block = $property->blocks()->create(['name' => $row['block']]);
                $property->blocks->push($block);
                $createdBlocks++;
            }

            // Defensive re-check — the unit may have been created by an
            // earlier row/import since preview ran.
            if (House::where('block_id', $block->id)->where('house_number', $row['unit'])->exists()) {
                continue;
            }

            $block->houses()->create([
                'house_number' => $row['unit'],
                'type' => self::UNIT_TYPE_ALIASES[$this->normalizeKey($row['unit_type'])],
                'bedrooms' => $row['bedrooms'] !== '' ? (int) $row['bedrooms'] : null,
                'monthly_rent' => $row['rent'],
                'deposit_amount' => $row['deposit'],
                'electricity_arrangement' => self::ARRANGEMENT_ALIASES[$this->normalizeKey($row['electricity'])] ?? 'separate_meter',
                'water_arrangement' => self::ARRANGEMENT_ALIASES[$this->normalizeKey($row['water'])] ?? 'shared',
            ]);
            $createdHouses++;
        }

        $this->createdPropertiesCount = $createdProperties;
        $this->createdBlocksCount = $createdBlocks;
        $this->createdHousesCount = $createdHouses;
        $this->imported = true;
    }

    public function startOver(): void
    {
        $this->reset(['file', 'rows', 'previewed', 'imported', 'createdPropertiesCount', 'createdBlocksCount', 'createdHousesCount']);
    }

    public function render()
    {
        $summary = ['valid' => 0, 'invalid' => 0, 'duplicate' => 0];

        foreach ($this->rows as $row) {
            $summary[$row['bucket']] = ($summary[$row['bucket']] ?? 0) + 1;
        }

        return view('livewire.properties.import', [
            'summary' => $summary,
        ]);
    }
}
