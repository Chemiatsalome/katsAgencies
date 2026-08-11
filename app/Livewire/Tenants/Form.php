<?php

namespace App\Livewire\Tenants;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?Tenant $tenant = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $id_number = '';

    public string $emergency_contact_name = '';

    public string $emergency_contact_phone = '';

    public int $payment_due_day = 1;

    public string $notes = '';

    public string $portal_email = '';

    public string $portal_password = '';

    public function mount(?Tenant $tenant = null): void
    {
        if ($tenant?->exists) {
            $this->authorize('update', $tenant);
            $this->tenant = $tenant;
            $this->name = $tenant->name;
            $this->phone = $tenant->phone;
            $this->email = (string) $tenant->email;
            $this->id_number = (string) $tenant->id_number;
            $this->emergency_contact_name = (string) $tenant->emergency_contact_name;
            $this->emergency_contact_phone = (string) $tenant->emergency_contact_phone;
            $this->payment_due_day = $tenant->payment_due_day;
            $this->notes = (string) $tenant->notes;
        } else {
            $this->authorize('create', Tenant::class);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:64'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'payment_due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($this->tenant) {
            $this->tenant->update($data);
        } else {
            $this->tenant = Tenant::create($data);
        }

        if (! $this->tenant->user_id && $this->portal_email) {
            $this->validate([
                'portal_email' => ['required', 'email', Rule::unique('users', 'email')],
                'portal_password' => ['required', 'string', 'min:8'],
            ]);

            $user = User::create([
                'name' => $this->tenant->name,
                'email' => $this->portal_email,
                'password' => Hash::make($this->portal_password),
                'email_verified_at' => now(),
            ]);
            $user->syncRoles(['tenant']);
            $this->tenant->update(['user_id' => $user->id]);
        }

        session()->flash('status', 'Tenant saved.');
        $this->redirectRoute('manage.tenants.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.tenants.form');
    }
}
