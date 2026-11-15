<?php

namespace App\Livewire\Properties;

use App\Models\House;
use App\Models\LandlordSetting;
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

    /**
     * A pure UI funnel for which Property::TYPES / House::TYPES options
     * step 1 and the Rentable Spaces groups offer — never persisted (see
     * Property::CATEGORY_TYPES's own docblock). Blank means "no category
     * chosen yet", which the blade reads as "show the category picker
     * instead of step 1" — $step itself is untouched by this, so every
     * existing step 1-4 test that never sets $category keeps working
     * exactly as before (render() just falls back to the unfiltered lists).
     */
    public string $category = '';

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

    /**
     * Optional landlord-given name per block index (0-based, same indexing
     * as blockLabels()/groups.*.block_index) — e.g. "Ground Floor" or
     * "East Wing" for an office/commercial building's floors or sections.
     * A blank entry (the default for every block) falls back to the
     * existing auto-lettered "Block A"/"Block B" naming in blockLabels().
     *
     * @var array<int, string>
     */
    public array $block_names = [];

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

        // A convenience starting point, not an assumption — the landlord's
        // own registered address, which is often but not always where a
        // property actually sits. Still a normal editable field below.
        if ($this->landlord_id) {
            $this->address = (string) LandlordSetting::get($this->landlord_id, 'contract_landlord_address', '');
            $lat = LandlordSetting::get($this->landlord_id, 'contract_landlord_lat');
            $lon = LandlordSetting::get($this->landlord_id, 'contract_landlord_lon');
            $this->latitude = $lat !== null ? (float) $lat : null;
            $this->longitude = $lon !== null ? (float) $lon : null;
        }
    }

    /**
     * 'one_bedroom' (today's long-standing default) stays the default for
     * every category except the two whose filtered dropdown wouldn't even
     * list it — Commercial and Office — where it's replaced with that
     * category's own first offered type instead, so the group's unit_type
     * is always something its own dropdown can actually show as selected.
     */
    private function defaultUnitType(): string
    {
        return match ($this->category) {
            'commercial', 'office' => House::typesForCategory($this->category)[0] ?? 'one_bedroom',
            default => 'one_bedroom',
        };
    }

    private function blankGroup(int $blockIndex = 0): array
    {
        return [
            'block_index' => $blockIndex,
            'unit_type' => $this->defaultUnitType(),
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

    /**
     * category === '' shows the picker; picking one here never gates
     * anything server-side (goToStep2()/goToStep3()/goToStep4()/save() are
     * completely unaware of $category) — it only narrows which options
     * render() hands the view. When a category maps to exactly one
     * TYPES value (Office, Mixed-use today), pre-selecting it skips an
     * otherwise pointless single-option dropdown.
     */
    public function chooseCategory(string $category): void
    {
        if (! in_array($category, Property::CATEGORIES, true)) {
            return;
        }

        $this->category = $category;

        $types = Property::CATEGORY_TYPES[$category] ?? [];
        if (count($types) === 1) {
            $this->type = $types[0];
            $this->updatedType();
        }

        // Picking a category always happens before step 2 is ever reached
        // (mount() already seeded one untouched blank group, and this is
        // the only way back there once chosen), so resetting here can
        // never discard anything the landlord actually filled in — it
        // just ensures the lone starting group's unit_type is one its own
        // now-filtered dropdown can actually show as selected.
        $this->groups = [$this->blankGroup()];
    }

    public function changeCategory(): void
    {
        $this->category = '';
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
     * same as House\Form's single-unit equivalent. Anything else residential
     * is left alone so a manually entered value is never silently
     * overwritten. Switching to a non-residential type (shop/stall/office)
     * always clears it — that field is hidden for those types in the
     * blade, so a stale count from a previous residential selection must
     * not survive into the saved House record.
     */
    public function updatedGroups($value, $key): void
    {
        if (! str_ends_with($key, '.unit_type')) {
            return;
        }

        $groupIndex = (int) explode('.', $key)[0];
        $type = $this->groups[$groupIndex]['unit_type'] ?? null;

        if ($type !== null && ! House::isResidentialType($type)) {
            $this->groups[$groupIndex]['bedrooms'] = null;

            return;
        }

        $implied = House::bedroomsImpliedByType($type);

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
            $custom = trim($this->block_names[$i] ?? '');
            $labels[$i] = $custom !== '' ? $custom : 'Block '.$this->blockLetter($i);
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
            $nounLower = $this->categoryCopy()['noun_lower'];
            $this->addError('reviewUnits', "Duplicate {$nounLower} numbers within the same block: ".$duplicates->implode(', ').". Each {$nounLower} needs a unique number within its block.");
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
            $this->layout === 'blocks' ? $this->blockLabels() : [],
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

    /**
     * Pure UI copy for the chosen category — headings, placeholders and the
     * terminology used for a "unit" throughout steps 1-4. '' (no category,
     * only reachable by a caller that never calls chooseCategory — i.e.
     * every pre-existing test) and 'residential' both resolve to exactly
     * today's pre-category wording, so neither changes anything that
     * already worked. Never changes which fields exist or what they
     * validate/save as — only the words around them.
     *
     * @return array<string, mixed>
     */
    private function categoryCopy(): array
    {
        $defaults = [
            'noun' => 'Unit',
            'noun_lower' => 'unit',
            'noun_plural_lower' => 'units',
            'step2_label' => 'Rentable Spaces',
            'block_noun_lower' => 'block',
            'block_count_label' => 'Number of blocks',
            'step1_image' => 'modern-villa-01.jpg',
            'step1_heading' => "Let's get your property set up",
            'step1_subtitle' => "A few steps to describe the property, its units, and rent — then you're ready to start managing it.",
            'step1_intro' => "Tell us about the property. You'll describe the actual units in the next step.",
            'name_placeholder' => 'e.g. Greenview Villas or Sunrise Apartments',
            'layout_heading' => 'How are the rentable spaces organized?',
            'layout_options' => [
                'standalone' => [
                    'label' => 'One standalone property',
                    'description' => 'A single house, maisonette or unit — no blocks needed.',
                ],
                'multiple_similar' => [
                    'label' => 'Multiple similar houses or villas',
                    'description' => 'Several units of the same or different types, no real blocks.',
                ],
                'blocks' => [
                    'label' => 'An apartment building with blocks',
                    'description' => 'Units organized into separate blocks (e.g. Block A, Block B).',
                ],
            ],
        ];

        $overrides = [
            'commercial' => [
                'noun' => 'Shop',
                'noun_lower' => 'shop',
                'noun_plural_lower' => 'shops',
                'step2_label' => 'Shops & Stalls',
                'block_noun_lower' => 'floor, wing or section',
                'block_count_label' => 'Number of floors, wings or sections',
                'step1_image' => 'category-commercial.png',
                'step1_heading' => "Let's get your commercial property set up",
                'step1_subtitle' => "A few steps to describe the property, its shops or spaces, and rent — then you're ready to start managing it.",
                'step1_intro' => "Tell us about the property. You'll describe the actual shops or spaces in the next step.",
                'name_placeholder' => 'e.g. Westlands Shopping Centre or Greenview Mall',
                'layout_heading' => 'How are the shops or spaces organized?',
                'layout_options' => [
                    'standalone' => [
                        'label' => 'A standalone shop or commercial space',
                        'description' => 'A single shop or commercial unit — no blocks needed.',
                    ],
                    'multiple_similar' => [
                        'label' => 'Multiple shops or retail spaces',
                        'description' => 'Several shops of the same or different types, no real blocks.',
                    ],
                    'blocks' => [
                        'label' => 'A shopping mall or centre with floors, wings or sections',
                        'description' => 'Shops organized into separate floors, wings or sections (e.g. Block A, Block B).',
                    ],
                ],
            ],
            'office' => [
                'noun' => 'Office',
                'noun_lower' => 'office',
                'noun_plural_lower' => 'offices',
                'step2_label' => 'Offices',
                'block_noun_lower' => 'floor or section',
                'block_count_label' => 'Number of floors or sections',
                'step1_image' => 'category-office.png',
                'step1_heading' => "Let's get your office property set up",
                'step1_subtitle' => "A few steps to describe the property, its offices, and rent — then you're ready to start managing it.",
                'step1_intro' => "Tell us about the property. You'll describe the actual offices in the next step.",
                'name_placeholder' => 'e.g. Westlands Business Plaza or Riverside Office Park',
                'layout_heading' => 'How are the offices organized?',
                'layout_options' => [
                    'standalone' => [
                        'label' => 'A standalone office',
                        'description' => 'A single office — no blocks needed.',
                    ],
                    'multiple_similar' => [
                        'label' => 'Multiple individual offices',
                        'description' => 'Several offices of the same or different sizes, no real blocks.',
                    ],
                    'blocks' => [
                        'label' => 'An office building with floors or sections',
                        'description' => 'Offices organized into separate floors or sections (e.g. Block A, Block B).',
                    ],
                ],
            ],
            'mixed_use' => [
                'noun' => 'Space',
                'noun_lower' => 'space',
                'noun_plural_lower' => 'spaces',
                'step2_label' => 'Rentable Spaces',
                'block_noun_lower' => 'block or floor',
                'block_count_label' => 'Number of blocks or floors',
                'step1_image' => 'category-mixed-use.png',
                'step1_heading' => "Let's get your mixed-use development set up",
                'step1_subtitle' => "A few steps to describe the development, its residential, retail and office spaces, and rent — then you're ready to start managing it.",
                'step1_intro' => "Tell us about the development. You'll describe its different spaces in the next step.",
                'name_placeholder' => 'e.g. Greenview Mixed-Use Development',
                'layout_heading' => 'How is this development organized?',
                'layout_options' => [
                    'standalone' => [
                        'label' => 'A single building combining different uses',
                        'description' => 'e.g. a shop with a residence above — no blocks needed.',
                    ],
                    'multiple_similar' => [
                        'label' => 'Multiple spaces of different types',
                        'description' => 'Residential units, shops and offices together, without separate named blocks.',
                    ],
                    'blocks' => [
                        'label' => 'A development containing multiple types of rentable spaces',
                        'description' => 'Residential units, shops and offices organized into separate blocks (e.g. Block A, Block B).',
                    ],
                ],
            ],
        ];

        return array_replace($defaults, $overrides[$this->category] ?? []);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.properties.wizard', [
            'landlords' => $user->hasRole('admin') ? User::role('landlord')->orderBy('name')->get() : collect(),
            'propertyTypes' => Property::typesForCategory($this->category),
            'houseTypes' => House::typesForCategory($this->category),
            'blockLabels' => $this->blockLabels(),
            'copy' => $this->categoryCopy(),
        ]);
    }
}
