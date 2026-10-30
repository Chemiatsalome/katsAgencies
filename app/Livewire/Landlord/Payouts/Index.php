<?php

namespace App\Livewire\Landlord\Payouts;

use App\Models\Disbursement;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Index extends Component
{
    /**
     * The landlord acknowledges a transfer they were told about. Scoped to
     * their own id, not just the route/middleware — findOrFail against
     * `landlord_id` means another landlord's payout 404s rather than ever
     * being reachable here, matching how confirmPayment/disputePayment
     * scope by tenancy ownership.
     */
    public function confirm(int $disbursementId): void
    {
        $landlord = Auth::user();
        $disbursement = Disbursement::where('landlord_id', $landlord->id)->findOrFail($disbursementId);

        if (! $disbursement->needsLandlordConfirmation() && ! $disbursement->isDisputed()) {
            return;
        }

        app(DisbursementService::class)->confirm($disbursement, $landlord);

        app(NotificationService::class)->markRelatedRead($disbursement, $landlord);

        $this->notifyAdmins(
            'disbursement_confirmed',
            'Landlord Confirmed a Payout',
            "{$landlord->name} confirmed receipt of KSh ".number_format((float) $disbursement->amount, 2).'.',
            $disbursement,
        );

        session()->flash('status', 'Payout confirmed.');
    }

    /**
     * The landlord disputes a transfer — missing, wrong amount, or wrong
     * details. Reachable from either 'awaiting_landlord_confirmation' or an
     * already-'confirmed' payout that turns out to be wrong, same as
     * Payment::disputePayment() allows reopening a confirmed claim.
     */
    public function dispute(int $disbursementId, string $reason): void
    {
        $landlord = Auth::user();
        $reason = trim($reason);

        if ($reason === '') {
            session()->flash('error', 'A reason is required to report a problem with a payout.');

            return;
        }

        $disbursement = Disbursement::where('landlord_id', $landlord->id)->findOrFail($disbursementId);

        app(DisbursementService::class)->dispute($disbursement, $landlord, $reason);

        app(NotificationService::class)->markRelatedRead($disbursement, $landlord);

        $this->notifyAdmins(
            'disbursement_disputed',
            'Landlord Disputed a Payout',
            "{$landlord->name} disputed a KSh ".number_format((float) $disbursement->amount, 2)." payout: \"{$reason}\"",
            $disbursement,
        );

        session()->flash('status', 'Problem reported — an admin will review it.');
    }

    private function notifyAdmins(string $eventType, string $title, string $body, Disbursement $disbursement): void
    {
        $notifications = app(NotificationService::class);

        User::role('admin')->each(fn (User $admin) => $notifications->notify(
            $admin, $eventType, $title, $body, ['in_app', 'email'], $disbursement,
        ));
    }

    public function render()
    {
        $landlordId = Auth::id();
        $service = app(DisbursementService::class);

        $disbursements = Disbursement::where('landlord_id', $landlordId)
            ->with(['recordedBy', 'documents'])
            ->latest('disbursed_at')
            ->latest('id')
            ->get();

        return view('livewire.landlord.payouts.index', [
            'disbursements' => $disbursements,
            'collected' => $service->collectedViaMpesa($landlordId),
            'disbursed' => $service->totalDisbursed($landlordId),
            'owed' => $service->owed($landlordId),
        ]);
    }
}
