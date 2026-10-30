<?php

namespace App\Livewire\Tenants;

use App\Models\House;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Services\TenancyProvisioningService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
class Import extends Component
{
    use WithFileUploads;

    public $file;

    public array $rows = [];

    public bool $previewed = false;

    public bool $imported = false;

    public array $createdTenantIds = [];

    public function mount(): void
    {
        $this->authorize('create', Tenant::class);
    }

    public function preview(): void
    {
        $this->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ]);

        $user = Auth::user();

        $properties = Property::accessibleBy($user)->with('blocks.houses')->get();

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
            $blockName = $col($csvRow, 'block');
            $unitNumber = $col($csvRow, 'unit');
            $name = $col($csvRow, 'name');
            $phone = $col($csvRow, 'phone');
            $rent = $col($csvRow, 'rent');
            $dueDate = $col($csvRow, 'due date') ?: $col($csvRow, 'due_date') ?: '1';
            $moveInDate = $col($csvRow, 'move in date') ?: $col($csvRow, 'move_in_date');

            $row = [
                'property' => $propertyName,
                'block' => $blockName,
                'unit' => $unitNumber,
                'name' => $name,
                'phone' => $phone,
                'rent' => $rent,
                'due_date' => $dueDate,
                'move_in_date' => $moveInDate,
                'bucket' => null,
                'reason' => null,
                'house_id' => null,
            ];

            if ($propertyName === '' || $blockName === '' || $unitNumber === '' || $name === '' || $phone === '' || $rent === '' || ! is_numeric($rent)) {
                $row['bucket'] = 'invalid';
                $row['reason'] = 'Missing or invalid required field (property, block, unit, name, phone, rent).';
                $rows[] = $row;

                continue;
            }

            if ($moveInDate !== '') {
                try {
                    $parsedMoveInDate = \Illuminate\Support\Carbon::parse($moveInDate);
                } catch (\Exception) {
                    $parsedMoveInDate = null;
                }

                if (! $parsedMoveInDate || $parsedMoveInDate->isFuture()) {
                    $row['bucket'] = 'invalid';
                    $row['reason'] = "Move In Date \"{$moveInDate}\" isn't a valid past date.";
                    $rows[] = $row;

                    continue;
                }

                $row['move_in_date'] = $parsedMoveInDate->format('Y-m-d');
            }

            $property = $properties->first(fn (Property $p) => strcasecmp($p->name, $propertyName) === 0);
            if (! $property) {
                $row['bucket'] = 'unknown_property';
                $row['reason'] = "No property named \"{$propertyName}\" found.";
                $rows[] = $row;

                continue;
            }

            $block = $property->blocks->first(fn ($b) => strcasecmp($b->name, $blockName) === 0);
            $house = $block?->houses->first(fn (House $h) => strcasecmp($h->house_number, $unitNumber) === 0);

            if (! $block || ! $house) {
                $row['bucket'] = 'missing_unit';
                $row['reason'] = "Block \"{$blockName}\" / Unit \"{$unitNumber}\" not found under {$property->name}.";
                $rows[] = $row;

                continue;
            }

            $fileKey = strtolower($phone).'|'.$house->id;
            $alreadyActiveTenancy = Tenant::where('phone', $phone)
                ->whereHas('tenancies', fn ($q) => $q->where('house_id', $house->id)->active())
                ->exists();

            if (isset($seenInFile[$fileKey]) || $alreadyActiveTenancy) {
                $row['bucket'] = 'duplicate';
                $row['reason'] = 'Same phone number and unit already imported or on file.';
                $rows[] = $row;

                continue;
            }

            if ($house->status !== 'vacant') {
                $row['bucket'] = 'missing_unit';
                $row['reason'] = "Unit \"{$unitNumber}\" is not currently vacant.";
                $rows[] = $row;

                continue;
            }

            $seenInFile[$fileKey] = true;
            $row['bucket'] = 'valid';
            $row['house_id'] = $house->id;
            $rows[] = $row;
        }

        fclose($handle);

        $this->rows = $rows;
        $this->previewed = true;
    }

    public function commit(): void
    {
        $this->authorize('create', Tenant::class);

        $provisioning = app(TenancyProvisioningService::class);
        $created = [];

        foreach ($this->rows as $row) {
            if ($row['bucket'] !== 'valid') {
                continue;
            }

            $existingTenant = Tenant::where('phone', $row['phone'])->first();
            $house = House::find($row['house_id']);

            if (! $house || $house->status !== 'vacant') {
                continue; // unit was taken by an earlier row/import since preview
            }

            $landlordId = Auth::user()->landlordId();
            $defaultDueDay = $landlordId ? (int) \App\Models\LandlordSetting::get($landlordId, 'default_rent_due_day', 5) : 5;

            $tenancyData = [
                'house_id' => $house->id,
                'move_in_date' => $row['move_in_date'] ?: now()->format('Y-m-d'),
                'monthly_rent' => $row['rent'],
                'deposit_required' => $house->deposit_amount,
                'rent_due_day' => (int) ($row['due_date'] ?: $defaultDueDay) ?: $defaultDueDay,
                'status' => 'active',
            ];

            $tenancy = $existingTenant
                ? $provisioning->createTenancyForExistingTenant($existingTenant, $tenancyData)
                : $provisioning->createTenantAndTenancy([
                    'name' => $row['name'],
                    'phone' => $row['phone'],
                    'payment_due_day' => $tenancyData['rent_due_day'],
                ], $tenancyData);

            $created[] = $tenancy->tenant_id;
        }

        $this->createdTenantIds = $created;
        $this->imported = true;
    }

    public function sendInvitation(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $tenancy = $tenant->mostRecentTenancy();
        abort_if(! $tenancy, 404);
        $this->authorize('update', $tenancy->house);

        TenantInvitation::create([
            'house_id' => $tenancy->house_id,
            'invited_by' => Auth::id(),
            'existing_tenant_id' => $tenant->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => $tenancy->monthly_rent,
            'deposit_required' => $tenancy->deposit_required,
            'rent_due_day' => $tenancy->dueDay(),
            'move_in_date' => $tenancy->move_in_date,
            'lease_end_date' => $tenancy->lease_end_date,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        session()->flash('status', "Registration invitation created for {$tenant->name}. Copy the link from Tenant Invitations to send it.");
    }

    public function startOver(): void
    {
        $this->reset(['file', 'rows', 'previewed', 'imported', 'createdTenantIds']);
    }

    public function render()
    {
        $summary = [
            'valid' => 0,
            'invalid' => 0,
            'duplicate' => 0,
            'missing_unit' => 0,
            'unknown_property' => 0,
        ];

        foreach ($this->rows as $row) {
            $summary[$row['bucket']] = ($summary[$row['bucket']] ?? 0) + 1;
        }

        return view('livewire.tenants.import', [
            'summary' => $summary,
            'createdTenants' => Tenant::whereIn('id', $this->createdTenantIds)->get(),
        ]);
    }
}
