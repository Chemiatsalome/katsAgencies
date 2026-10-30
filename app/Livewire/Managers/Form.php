<?php

namespace App\Livewire\Managers;

use App\Models\Manager;
use App\Models\Property;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?Manager $manager = null;

    public int $step = 1;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public bool $all_properties = false;

    /** @var int[] */
    public array $property_ids = [];

    /** @var string[] */
    public array $permissions = [];

    public function mount(?Manager $manager = null): void
    {
        if ($manager?->exists) {
            $this->authorize('update', $manager);
            $this->manager = $manager;
            $this->name = $manager->name;
            $this->phone = $manager->phone;
            $this->email = $manager->email;
            $this->all_properties = $manager->all_properties;
            $this->property_ids = $manager->properties()->pluck('properties.id')->all();
            $this->permissions = $manager->permissions ?? [];
        } else {
            $this->authorize('create', Manager::class);
        }
    }

    public function goToStep2(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => $this->emailRules(),
        ]);

        $this->step = 2;
    }

    public function backToStep1(): void
    {
        $this->step = 1;
    }

    /**
     * The invited email must be free in both `managers` (no duplicate/pending
     * invite) and `users` (no existing account of any role) — otherwise
     * acceptance later fails with a raw DB unique-constraint error instead of
     * a message the landlord can act on here, up front.
     */
    private function emailRules(): array
    {
        return [
            'required', 'email', 'max:255',
            Rule::unique('managers', 'email')->ignore($this->manager?->id),
            Rule::unique('users', 'email')->ignore($this->manager?->user_id),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => $this->emailRules(),
        ]);

        if (! $this->all_properties && empty($this->property_ids)) {
            $this->addError('property_ids', 'Select at least one property, or grant access to all properties.');

            return;
        }

        $validPermissions = array_intersect($this->permissions, PermissionSeeder::managerPermissions());

        $data = [
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'all_properties' => $this->all_properties,
            'permissions' => array_values($validPermissions),
        ];

        if ($this->manager) {
            // sync() never fires a model 'updated' event, so the pivot
            // change is invisible to Manager's own activity log unless
            // logged explicitly here — this (and granted permissions,
            // already covered by logOnly() below) are the two things a
            // landlord actually tunes most, and previously left zero trail.
            $propertyScopeBefore = $this->manager->all_properties
                ? 'all'
                : $this->manager->properties()->pluck('properties.name')->sort()->values()->all();
            $permissionsBefore = $this->manager->user?->getPermissionNames()->sort()->values()->all() ?? [];

            $this->manager->update($data);
            $this->manager->properties()->sync($this->all_properties ? [] : $this->property_ids);

            $propertyScopeAfter = $this->all_properties
                ? 'all'
                : Property::whereIn('id', $this->property_ids)->pluck('name')->sort()->values()->all();

            if ($propertyScopeBefore !== $propertyScopeAfter) {
                activity()
                    ->causedBy(Auth::user())
                    ->performedOn($this->manager)
                    ->withProperties(['before' => $propertyScopeBefore, 'after' => $propertyScopeAfter])
                    ->log('Manager property access changed');
            }

            // An already-active manager's grants must take effect immediately,
            // not just get staged for a future acceptance that already happened.
            $this->manager->user?->syncPermissions($validPermissions);

            // Previously silent — a manager only ever found out their
            // access changed by hitting a 403 or noticing a sidebar link
            // had disappeared.
            $permissionsAfter = collect($validPermissions)->sort()->values()->all();
            if ($this->manager->user && ($propertyScopeBefore !== $propertyScopeAfter || $permissionsBefore !== $permissionsAfter)) {
                app(\App\Services\NotificationService::class)->notify(
                    $this->manager->user,
                    'manager_access_changed',
                    'Your Access Was Updated',
                    'Your landlord changed what you can access or do in Kats Agencies. Check your sidebar for what\'s changed.',
                    ['in_app'],
                    $this->manager,
                );
            }

            if ($this->manager->user && $this->manager->user->email !== $this->email) {
                $this->manager->user->update(['email' => $this->email]);
            }

            session()->flash('status', 'Manager updated.');
            $this->redirectRoute('manage.managers.index', navigate: true);

            return;
        }

        $this->manager = Manager::create($data + [
            'landlord_id' => Auth::id(),
            'status' => 'invited',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
        ]);

        if (! $this->all_properties) {
            $this->manager->properties()->attach($this->property_ids);
        }

        $this->step = 3;
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.managers.form', [
            'properties' => $user->properties()->orderBy('name')->get(['id', 'name']),
            'permissionGroups' => PermissionSeeder::managerPermissionGroups(),
            'permissionLabels' => PermissionSeeder::permissionLabels(),
        ]);
    }
}
