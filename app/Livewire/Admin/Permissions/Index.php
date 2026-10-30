<?php

namespace App\Livewire\Admin\Permissions;

use Database\Seeders\PermissionSeeder;
use Livewire\Component;
use Spatie\Permission\Models\Role;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    /** @var array<string, array<string, bool>> role name => permission name => bool */
    public array $matrix = [];

    public array $roles = [];

    /**
     * "Manager Delegation" permissions are deliberately excluded from this
     * role-level matrix (PermissionSeeder::groups() still includes them so
     * the Permission records themselves get seeded). Per that seeder's own
     * documented intent, those permissions are only ever meant to be granted
     * per-manager, directly on the manager's own User record, by the landlord
     * who delegates them (see Managers\Form). Granting one at the `manager`
     * ROLE level here would silently hand it to every manager working for
     * every landlord platform-wide — Spatie's permission check doesn't
     * distinguish "inherited from role" from "granted directly" — overriding
     * each landlord's individual delegation choices with no warning.
     */
    private function editableGroups(): array
    {
        return collect(PermissionSeeder::groups())
            ->except('Manager Delegation')
            ->all();
    }

    public function mount(): void
    {
        $this->roles = Role::orderBy('name')->pluck('name')->all();

        foreach ($this->roles as $role) {
            $roleModel = Role::findByName($role);
            $granted = $roleModel->permissions->pluck('name')->all();

            foreach (collect($this->editableGroups())->flatten() as $permission) {
                $this->matrix[$role][$permission] = in_array($permission, $granted, true);
            }
        }
    }

    public function toggle(string $role, string $permission): void
    {
        if ($role === 'admin') {
            return;
        }

        abort_unless(in_array($permission, collect($this->editableGroups())->flatten()->all(), true), 422);

        $roleModel = Role::findByName($role);
        $newState = ! $this->matrix[$role][$permission];

        if ($newState) {
            $roleModel->givePermissionTo($permission);
        } else {
            $roleModel->revokePermissionTo($permission);
        }

        $this->matrix[$role][$permission] = $newState;
    }

    public function render()
    {
        return view('livewire.admin.permissions.index', [
            'groups' => $this->editableGroups(),
        ]);
    }
}
