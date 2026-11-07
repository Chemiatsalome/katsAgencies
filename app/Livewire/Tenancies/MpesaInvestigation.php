<?php

namespace App\Livewire\Tenancies;

use App\Models\MpesaTransaction;
use App\Services\MpesaInvestigationService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The landlord-facing counterpart to Admin\Payments\Index — reached only
 * from a notification about one specific stuck transaction
 * (mpesa_cancellation_requested / mpesa_callback_missed; see
 * AppNotification::actionUrl()), never browsed to directly. The landlord's
 * only two actions here are pure acknowledgements, never a credit or a
 * resolution — see MpesaInvestigationService::recordLandlordReceivedPayment()/
 * recordLandlordDidNotReceivePayment()'s docblocks for exactly why.
 */
#[Layout('layouts.admin')]
class MpesaInvestigation extends Component
{
    public MpesaTransaction $transaction;

    public function mount(MpesaTransaction $transaction): void
    {
        // Same policy Tenancies\Show itself is gated by — ultimately the
        // same Property::accessibleBy() chain, so a manager restricted to
        // specific properties is restricted here identically, never
        // granted their landlord's full portfolio by mistake.
        $this->authorize('view', $transaction->tenancy);

        abort_if($transaction->purpose === 'sms_topup', 404);

        $this->transaction = $transaction;
    }

    public function confirmReceived(): void
    {
        $this->respond(fn () => app(MpesaInvestigationService::class)
            ->recordLandlordReceivedPayment($this->transaction, Auth::user()));
    }

    public function confirmNotReceived(): void
    {
        $this->respond(fn () => app(MpesaInvestigationService::class)
            ->recordLandlordDidNotReceivePayment($this->transaction, Auth::user()));
    }

    private function respond(\Closure $action): void
    {
        try {
            $this->transaction = $action();
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Your response has been recorded.');
    }

    public function render()
    {
        $this->transaction->load([
            'tenancy.tenant', 'tenancy.house.block.property',
            'statusQueries', 'cancellationRequestedBy', 'landlordResponseBy',
        ]);

        return view('livewire.tenancies.mpesa-investigation');
    }
}
