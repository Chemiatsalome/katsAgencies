<?php

namespace App\Livewire\Admin\Landlords;

use App\Mail\LandlordApplicationApprovedPendingVerificationMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        // Reactivating a landlord who was approved but never finished
        // verifying their email must not grant access via this side door
        // any more than Applications::approve() does (see
        // User::isAwaitingEmailVerification()) — record that this admin
        // intends to approve them (approved_at) but leave the actual
        // status flip to whichever confirms their email next.
        if ($status === 'active' && ! $user->hasVerifiedEmail()) {
            $user->update(['approved_at' => $user->approved_at ?? now()]);

            try {
                Mail::to($user->email)->send(
                    new LandlordApplicationApprovedPendingVerificationMail($user->name, $user->verificationUrl())
                );

                session()->flash('status', "{$user->name}'s email isn't verified yet, so they can't be activated yet — we've emailed them a verification link instead. They'll activate automatically once they confirm it.");
            } catch (\Throwable $e) {
                Log::warning('Landlord reactivation verification email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

                session()->flash('status', "{$user->name}'s email isn't verified yet, so they can't be activated yet — but the verification email failed to send. Let them know directly, or ask them to use \"Resend verification\" on their own login attempt.");
            }

            return;
        }

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
