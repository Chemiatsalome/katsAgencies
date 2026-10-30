<?php

namespace App\Livewire\Properties;

use App\Models\House;
use App\Models\Property;
use App\Models\User;
use App\Services\PropertyProvisioningService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Replaces the old "create a Property, then separately create a Block,
 * then separately create each House" flow with one guided wizard — the
 * three steps a landlord used to have to understand and navigate on their
 * own are now one continuous flow, and a standalone house never requires
 * them to know blocks exist at all (see provisionLayoutBlocks() and
 * PropertyProvisioningService, which always creates the one hidden "Main"
 * block for them).
 *
 * All financial writes stop at House.deposit_amount (the amount
 * *required*) — this component and PropertyProvisioningService never touch
 * Tenancy or DepositPayment. Actually recording a deposit received only
 * ever happens later, through the existing tenant-adding flow.
 */
#[Layout('layouts.admin')]
class Wizard extends Component
{
    use WithFileUploads;

    public int $step = 1;

    // Step 1
    public ?int $landlord_id = null;

    public string $type = 'other';

    public string $name = '';

    public string $address = '';

    /** Only ever set when the landlord explicitly confirmed a location-autocomplete suggestion — never from free typing. */
    public ?float $latitude = null;

    public ?float $longitude = null;

    public $photo = null;

    // Step 2 + 3
    public string $layout = 'standalone';

    public int $block_count = 1;

    /** @var array<int, array<string, mixed>> */
    public array $groups = [];

    // Step 4
    public array $reviewUnits = [];

    public bool $submitting = false;

    public function mount(): void
    {
        $this->authorize('create', Property::class);

        $this->landlord_id = Auth::user()->hasRole('admin') ? null : Auth::user()->landlordId();
        $this->groups = [$this->blankGroup()];
    }

    private function blankGroup(int $blockIndex = 0): array
    {
        return [
            'block_index' => $blockIndex,
            'unit_type' => 'one_bedroom',
            'bedrooms' => null,
            'count' => 1,
            'starting_number' => (string) $this->nextStartingNumber($blockIndex),
            'monthly_rent' => '',
            'electricity_arrangement' => 'separate_meter',
            'water_arrangement' => 'shared',
            'deposit_mode' => 'months',
            'deposit_months' => 1,
            'deposit_custom_amount' => '',
            'deposit_note' => '',
        ];
    }

    /** Continues the running unit count already allocated within this block, so a new group's default never collides with an earlier one's numbers. */
    private function nextStartingNumber(int $blockIndex): int
    {
        $allocated = collect($this->groups)
            ->where('block_index', $blockIndex)
            ->sum(fn (array $g) => (int) ($g['count'] ?? 0));

        return $allocated + 1;
    }

    public function updatedType(): void
    {
        // A sensible pre-selection, not a hard gate — every option stays
        // changeable on the very next field.
        $this->layout = in_array($this->type, ['individual_house', 'maisonette'], true)
            ? 'standalone'
            : 'multiple_similar';

        $this->applyLayoutConstraints();
    }

    /**
     * Only the explicitly numbered unit types (one/two/three/four bedroom)
     * imply a bedroom count — picking one always sets bedrooms to match, the
     * same as House\Form's single-unit equivalent. Anything else is left
     * alone so a manually entered value is never silently overwritten.
     */
    public function updatedGroups($value, $key): void
    {
        if (! str_ends_with($key, '.unit_type')) {
            return;
        }

        $groupIndex = (int) explode('.', $key)[0];
        $implied = House::bedroomsImpliedByType($this->groups[$groupIndex]['unit_type'] ?? null);

        if ($implied !== null) {
            $this->groups[$groupIndex]['bedrooms'] = $implied;
        }
    }

    public function updatedLayout(): void
    {
        $this->applyLayoutConstraints();
    }

    public function updatedBlockCount(): void
    {
        $this->applyLayoutConstraints();
    }

    /**
     * A standalone property never shows a block step and only ever has
     * exactly one unit — enforced here, not just hidden in the Blade, so a
     * tampered payload can't smuggle extra units/blocks past it either.
     */
    private function applyLayoutConstraints(): void
    {
        if ($this->layout === 'standalone') {
            $this->block_count = 1;
            $this->groups = [$this->groups[0] ?? $this->blankGroup()];
            $this->groups[0]['count'] = 1;
            $this->groups[0]['block_index'] = 0;

            return;
        }

        if ($this->layout === 'multiple_similar') {
            $this->block_count = 1;
            foreach ($this->groups as &$group) {
                $group['block_index'] = 0;
            }

            return;
        }

        // 'blocks' — clamp every group's block_index into range if the
        // landlord just shrank block_count below where some groups pointed.
        foreach ($this->groups as &$group) {
            $group['block_index'] = min($group['block_index'], max(0, $this->block_count - 1));
        }
    }

    public function addGroup(): void
    {
        $blockIndex = $this->layout === 'blocks' ? max(0, $this->block_count - 1) : 0;
        $this->groups[] = $this->blankGroup($blockIndex);
    }

    public function removeGroup(int $index): void
    {
        if (count($this->groups) <= 1) {
            return;
        }

        unset($this->groups[$index]);
        $this->groups = array_values($this->groups);
    }

    public function goToStep2(): void
    {
        $this->validate([
            'type' => ['required', Rule::in(Property::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photo' => ['nullable', 'image', 'max:4096'],
            'landlord_id' => ['required', Rule::exists('users', 'id')],
        ]);

        $this->step = 2;
    }

    public function goToStep3(): void
    {
        $this->validate($this->step2Rules());
        $this->step = 3;
    }

    public function backToStep(int $step): void
    {
        $this->step = $step;
    }

    private function step2Rules(): array
    {
        return [
            'layout' => ['required', Rule::in(['standalone', 'multiple_similar', 'blocks'])],
            'block_count' => ['required_if:layout,blocks', 'integer', 'min:1', 'max:50'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.block_index' => ['required', 'integer', 'min:0'],
            'groups.*.unit_type' => ['required', Rule::in(House::TYPES)],
            'groups.*.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'groups.*.count' => ['required', 'integer', 'min:1', 'max:50'],
            'groups.*.starting_number' => ['required', 'string', 'max:50'],
            'groups.*.monthly_rent' => ['required', 'numeric', 'min:0'],
            'groups.*.electricity_arrangement' => ['required', Rule::in(['shared', 'separate_meter', 'included'])],
            'groups.*.water_arrangement' => ['required', Rule::in(['shared', 'separate_meter', 'included'])],
        ];
    }

    public function goToStep4(): void
    {
        $this->validate($this->step2Rules() + [
            'groups.*.deposit_mode' => ['required', Rule::in(['none', 'months', 'custom'])],
            'groups.*.deposit_months' => ['required_if:groups.*.deposit_mode,months', 'nullable', 'integer', 'min:1', 'max:12'],
            'groups.*.deposit_custom_amount' => ['required_if:groups.*.deposit_mode,custom', 'nullable', 'numeric', 'min:0'],
            'groups.*.deposit_note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->reviewUnits = $this->buildReviewUnits();
        $this->step = 4;
    }

    /**
     * Flattens $groups into one row per unit, resolving each unit's actual
     * deposit_amount from its group's deposit_mode. Rebuilt from scratch
     * every time step 4 is entered — going back to step 2/3 and changing
     * counts/types intentionally discards any prior per-row review edits,
     * since there's no reliable way to re-map edited rows onto a changed
     * group structure. This is the simplest rule that can't silently drift.
     */
    private function buildReviewUnits(): array
    {
        $blockLabels = $this->blockLabels();
        $rows = [];

        foreach ($this->groups as $groupIndex => $group) {
            $numbers = $this->generateHouseNumbers((string) $group['starting_number'], (int) $group['count']);
            $depositAmount = $this->resolveDepositAmount($group);
            $description = $group['deposit_mode'] === 'custom' && filled($group['deposit_note'])
                ? '[Deposit arrangement: '.$group['deposit_note'].']'
                : null;

            foreach ($numbers as $houseNumber) {
                $rows[] = [
                    'group_index' => $groupIndex,
                    'block_index' => $group['block_index'],
                    'block_label' => $blockLabels[$group['block_index']] ?? 'Main',
                    'house_number' => $houseNumber,
                    'type' => $group['unit_type'],
                    'bedrooms' => $group['bedrooms'],
                    'monthly_rent' => $group['monthly_rent'],
                    'deposit_amount' => $depositAmount,
                    'electricity_arrangement' => $group['electricity_arrangement'],
                    'water_arrangement' => $group['water_arrangement'],
                    'description' => $description,
                ];
            }
        }

        return $rows;
    }

    private function resolveDepositAmount(array $group): string
    {
        return match ($group['deposit_mode']) {
            'none' => '0',
            'months' => (string) ((float) $group['monthly_rent'] * (int) $group['deposit_months']),
            'custom' => (string) $group['deposit_custom_amount'],
            default => '0',
        };
    }

    /** @return string[] */
    private function generateHouseNumbers(string $start, int $count): array
    {
        if (ctype_digit($start)) {
            return array_map(fn (int $i) => (string) ((int) $start + $i), range(0, $count - 1));
        }

        // A non-numeric starting point (e.g. "Ground Floor") still needs a
        // unique number per unit — suffix it rather than silently colliding.
        return $count === 1 ? [$start] : array_map(fn (int $i) => "{$start} ".($i + 1), range(0, $count - 1));
    }

    /** @return array<int, string> 0-based block index -> display label, matching the order PropertyProvisioningService creates blocks in. */
    private function blockLabels(): array
    {
        if ($this->layout !== 'blocks') {
            return [0 => 'Main'];
        }

        $labels = [];
        for ($i = 0; $i < $this->block_count; $i++) {
            $labels[$i] = 'Block '.$this->blockLetter($i);
        }

        return $labels;
    }

    private function blockLetter(int $index): string
    {
        $letters = '';

        do {
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $letters;
    }

    public function save(): void
    {
        if ($this->submitting) {
            return;
        }

        $this->submitting = true;

        $this->validate([
            'reviewUnits' => ['required', 'array', 'min:1'],
            'reviewUnits.*.house_number' => ['required', 'string', 'max:255'],
            'reviewUnits.*.type' => ['required', Rule::in(House::TYPES)],
            'reviewUnits.*.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'reviewUnits.*.monthly_rent' => ['required', 'numeric', 'min:0'],
            'reviewUnits.*.deposit_amount' => ['required', 'numeric', 'min:0'],
            'reviewUnits.*.electricity_arrangement' => ['required', Rule::in(['shared', 'separate_meter', 'included'])],
            'reviewUnits.*.water_arrangement' => ['required', Rule::in(['shared', 'separate_meter', 'included'])],
        ]);

        $duplicates = collect($this->reviewUnits)
            ->groupBy('block_index')
            ->map(fn ($rows) => collect($rows)->pluck('house_number')->duplicates())
            ->flatten()
            ->unique();

        if ($duplicates->isNotEmpty()) {
            $this->addError('reviewUnits', 'Duplicate unit numbers within the same block: '.$duplicates->implode(', ').'. Each unit needs a unique number within its block.');
            $this->submitting = false;

            return;
        }

        if (! Auth::user()->hasRole('admin')) {
            $this->landlord_id = Auth::user()->landlordId();
        }

        $photoPath = $this->photo?->store('property-photos', 'public');

        $property = app(PropertyProvisioningService::class)->provision(
            [
                'landlord_id' => $this->landlord_id,
                'name' => $this->name,
                'type' => $this->type,
                'address' => $this->address ?: null,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'photo_path' => $photoPath,
            ],
            $this->layout,
            $this->block_count,
            $this->reviewUnits,
        );

        // The "just onboarded" signal travels as a query param on the
        // destination URL, not a session flash — Livewire's component
        // update requests run with middleware (including session
        // persistence) disabled, so a flash set here is not reliably
        // readable even on the very next real page load. A query param has
        // no such dependency.
        //
        // Not a Livewire navigate:true redirect either — that always
        // *pushes* a new history entry, leaving this now-completed,
        // multi-step wizard as the entry right before the property page.
        // The browser's own Back button would then reopen the wizard
        // mid-review. Dispatching this and replacing the URL client-side
        // (see wizard.blade.php) instead collapses the wizard's history
        // entry into the property page, so Back skips straight past it to
        // wherever the wizard was opened from.
        $this->dispatch('property-created', url: route('manage.properties.show', ['property' => $property, 'onboarded' => 1]));
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.properties.wizard', [
            'landlords' => $user->hasRole('admin') ? User::role('landlord')->orderBy('name')->get() : collect(),
            'propertyTypes' => Property::TYPES,
            'houseTypes' => House::TYPES,
            'blockLabels' => $this->blockLabels(),
        ]);
    }
}
