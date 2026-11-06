<?php

namespace App\Livewire\Admin\Landlords;

use App\Mail\LandlordApplicationApprovedMail;
use App\Mail\LandlordApplicationApprovedPendingVerificationMail;
use App\Mail\LandlordApplicationRejectedMail;
use App\Models\RejectedLandlordApplication;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Applications extends Component
{
    use WithPagination;

    /** Common, legitimate reasons an application gets turned down — picking one fills the reason below, which is then both stored and emailed to the applicant. "Other" leaves it blank for a one-off explanation. */
    public const REASON_PRESETS = [
        'unverifiable_id' => "We couldn't verify the National ID/passport number you provided.",
        'implausible_details' => "The details provided (unit count, location, or business name) didn't look complete or accurate.",
        'duplicate' => 'This looks like a duplicate of another application, or a repeat of one we already declined.',
        'unreachable_contact' => "We couldn't reach you using the phone number or email provided.",
        'suspected_fraud' => "We weren't able to confirm this application is genuine.",
        'other' => 'Other (write your own reason below)',
    ];

    public ?int $rejectingApplicantId = null;

    public string $rejectionPreset = '';

    public string $rejectionReason = '';

    /**
     * Approval and email verification are independent requirements (see
     * User::isAwaitingEmailVerification()) — activating regardless of
     * verification status is what used to let an approval email tell a
     * landlord to log in right before the 'verified' gate turned around and
     * blocked them. So: only flip `status` to active here if they've
     * already verified; otherwise record the approval (`approved_at`) and
     * leave activation to whichever confirms their email next
     * (ActivateApprovedLandlordOnVerification) — and email them the actual
     * remaining step instead of a login link that wouldn't yet work.
     */
    public function approve(int $userId): void
    {
        $user = User::role('landlord')->whereNull('approved_at')->findOrFail($userId);
        $verified = $user->hasVerifiedEmail();

        $user->update([
            'approved_at' => now(),
            'status' => $verified ? 'active' : $user->status,
        ]);

        // The approval itself (above) is what actually grants access —
        // never roll it back over a transport hiccup on the email that
        // merely announces it. The admin still needs to know the email
        // didn't go out, since nothing else will tell the applicant their
        // account is ready.
        try {
            Mail::to($user->email)->send(
                $verified
                    ? new LandlordApplicationApprovedMail($user->name, route('login'))
                    : new LandlordApplicationApprovedPendingVerificationMail($user->name, $user->verificationUrl())
            );

            session()->flash('status', "{$user->name}'s application was approved and they've been emailed.");
        } catch (\Throwable $e) {
            Log::warning('Landlord application approval email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            session()->flash('status', "{$user->name}'s application was approved, but the notification email failed to send — let them know directly.");
        }
    }

    public function openRejectForm(int $userId): void
    {
        $this->rejectingApplicantId = $userId;
        $this->rejectionPreset = '';
        $this->rejectionReason = '';
    }

    public function cancelRejectForm(): void
    {
        $this->reset(['rejectingApplicantId', 'rejectionPreset', 'rejectionReason']);
    }

    public function updatedRejectionPreset(): void
    {
        if ($this->rejectionPreset !== '' && $this->rejectionPreset !== 'other') {
            $this->rejectionReason = self::REASON_PRESETS[$this->rejectionPreset] ?? '';
        }
    }

    public function reject(): void
    {
        // Scoped to never-approved signups only — a landlord who was active
        // before and is currently deactivated/suspended must never be
        // reachable here, since this deletes the account outright.
        $user = User::role('landlord')->where('status', 'inactive')->whereNull('approved_at')->findOrFail($this->rejectingApplicantId);

        $this->validate([
            'rejectionReason' => ['required', 'string', 'max:2000'],
        ], [], ['rejectionReason' => 'reason']);

        $contactEmail = config('mail.support_contact_email');

        // Told why, and where to go if they think it's wrong — a hard
        // delete with zero explanation was the thing being fixed here. Sent
        // before any destructive write below: if it can't be delivered, the
        // applicant must not be wiped out with no record of why, so this
        // bails out and leaves the application pending for a retry instead
        // of silently deleting them in the dark.
        try {
            Mail::to($user->email)->send(
                new LandlordApplicationRejectedMail($user->name, $this->rejectionReason, $contactEmail)
            );
        } catch (\Throwable $e) {
            Log::warning('Landlord application rejection email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            session()->flash('status', "Couldn't email {$user->name} the rejection reason, so the application was left pending — try again.");

            return;
        }

        // A snapshot of who they said they were survives the account
        // being deleted, so a repeat attempt under a different email can
        // still be matched by phone, national ID, or name instead of
        // vanishing without a trace.
        RejectedLandlordApplication::create([
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'national_id' => $user->national_id,
            'business_name' => $user->business_name,
            'property_location' => $user->property_location,
            'intended_unit_count' => $user->intended_unit_count,
            'reason' => $this->rejectionReason,
            'applied_at' => $user->created_at,
            'rejected_by' => Auth::id(),
            'rejected_at' => now(),
        ]);

        $user->delete();

        $this->cancelRejectForm();
        session()->flash('status', 'Application rejected and the applicant has been emailed.');
    }

    public function render()
    {
        $pending = User::role('landlord')
            ->where('status', 'inactive')
            ->whereNull('approved_at')
            ->latest()
            ->paginate(15);

        // Every currently-pending applicant, not just this page — two
        // simultaneous applications matching each other must be flagged
        // even when pagination splits them onto different pages.
        $allPending = User::role('landlord')
            ->where('status', 'inactive')
            ->whereNull('approved_at')
            ->get(['id', 'name', 'email', 'phone', 'national_id']);

        $duplicateSignalFor = $pending->getCollection()
            ->mapWithKeys(fn (User $applicant) => [$applicant->id => $this->duplicateSignalFor($applicant, $allPending)]);

        return view('livewire.admin.landlords.applications', [
            'pending' => $pending,
            'duplicateSignalFor' => $duplicateSignalFor,
        ]);
    }

    /**
     * Checked against two pools: every other currently-pending applicant
     * (catches the "same person applied twice right now" case — which,
     * notably, doesn't always share a phone or ID, just a name) and every
     * past rejection (catches a repeat attempt under new details). Name
     * match is the weakest signal here — two different real people can
     * share a name — so it's worded as a prompt to double-check, not an
     * accusation.
     */
    private function duplicateSignalFor(User $applicant, \Illuminate\Support\Collection $pendingBatch): ?string
    {
        $rejections = RejectedLandlordApplication::query()
            ->where(fn ($q) => $q
                ->where('email', $applicant->email)
                ->orWhere(fn ($q2) => $applicant->phone && $q2->where('phone', $applicant->phone))
                ->orWhere(fn ($q2) => $applicant->national_id && $q2->where('national_id', $applicant->national_id))
                ->orWhere('name', $applicant->name))
            ->latest('rejected_at')
            ->first();

        if ($rejections) {
            $field = match (true) {
                $rejections->email === $applicant->email => 'email',
                $applicant->phone && $rejections->phone === $applicant->phone => 'phone number',
                $applicant->national_id && $rejections->national_id === $applicant->national_id => 'national ID',
                default => 'name',
            };

            return "Same {$field} as an application rejected on {$rejections->rejected_at->format('d M Y')}.";
        }

        $otherPending = $pendingBatch->first(function (User $other) use ($applicant) {
            if ($other->id === $applicant->id) {
                return false;
            }

            return $other->email === $applicant->email
                || ($applicant->phone && $other->phone === $applicant->phone)
                || ($applicant->national_id && $other->national_id === $applicant->national_id)
                || strcasecmp(trim($other->name), trim($applicant->name)) === 0;
        });

        if ($otherPending) {
            $field = match (true) {
                $otherPending->email === $applicant->email => 'email',
                $applicant->phone && $otherPending->phone === $applicant->phone => 'phone number',
                $applicant->national_id && $otherPending->national_id === $applicant->national_id => 'national ID',
                default => 'name',
            };

            return "Same {$field} as another application pending right now ({$otherPending->email}).";
        }

        return null;
    }
}
