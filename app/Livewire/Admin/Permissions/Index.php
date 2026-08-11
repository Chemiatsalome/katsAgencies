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

    public function mount(): void
    {
        $this->roles = Role::orderBy('name')->pluck('name')->all();

        foreach ($this->roles as $role) {
            $roleModel = Role::findByName($role);
            $granted = $roleModel->permissions->pluck('name')->all();

            foreach (collect(PermissionSeeder::groups())->flatten() as $permission) {
                $this->matrix[$role][$permission] = in_array($permission, $granted, true);
            }
        }
    }

    public function toggle(string $role, string $permission): void
    {
        if ($role === 'admin') {
            return;
        }

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
            'groups' => PermissionSeeder::groups(),
        ]);
    }
}
