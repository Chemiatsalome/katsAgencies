<?php

namespace App\Livewire\Admin\Landlords;

use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = 'all';

    public function updateStatus(int $userId, string $status): void
    {
        abort_unless(in_array($status, ['active', 'inactive', 'suspended'], true), 422);

        $user = User::role('landlord')->findOrFail($userId);
        $previousStatus = $user->status;

        $user->update([
            'status' => $status,
            'approved_at' => $status === 'active' ? ($user->approved_at ?? now()) : $user->approved_at,
        ]);

        // Previously silent — unlike the application approve/reject flow
        // (which always emails a reason), changing an already-active
        // landlord's status gave no proactive notice at all; they only
        // found out reactively, at their next forced logout/login attempt
        // (see EnsureAccountIsActive), with no explanation of why.
        if ($previousStatus !== $status) {
            $contact = Setting::get('org_contact_email');
            $contactLine = $contact ? " If you believe this is a mistake, contact {$contact}." : '';

            $message = match ($status) {
                'suspended' => "Your Kats Agencies account has been suspended by an administrator.{$contactLine}",
                'inactive' => "Your Kats Agencies account has been deactivated by an administrator.{$contactLine}",
                'active' => 'Your Kats Agencies account is active again.',
                default => 'Your Kats Agencies account status has changed.',
            };

            app(NotificationService::class)->notify(
                $user,
                'landlord_status_changed',
                $status === 'active' ? 'Your Account Is Active Again' : 'Your Account Status Has Changed',
                $message,
                ['in_app', 'email'],
                $user,
            );
        }
    }

    public function render()
    {
        $query = User::role('landlord')
            ->withCount('properties')
            ->with('properties.blocks.houses')
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest();

        $landlords = $query->paginate(15)->through(function (User $landlord) {
            $houses = $landlord->properties->flatMap(fn ($p) => $p->blocks->flatMap(fn ($b) => $b->houses));

            return (object) [
                'user' => $landlord,
                'propertiesCount' => $landlord->properties_count,
                'unitsCount' => $houses->count(),
                'occupiedCount' => $houses->where('status', 'occupied')->count(),
                'vacantCount' => $houses->where('status', 'vacant')->count(),
            ];
        });

        return view('livewire.admin.landlords.index', ['landlords' => $landlords]);
    }
}
