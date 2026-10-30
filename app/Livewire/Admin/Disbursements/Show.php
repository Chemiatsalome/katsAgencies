<?php

namespace App\Livewire\Admin\Disbursements;

use App\Models\Disbursement;
use App\Models\User;
use App\Services\DisbursementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Show extends Component
{
    public User $landlord;

    public function mount(User $landlord): void
    {
        $this->landlord = $landlord;
    }

    /** Admin-only correction: this disbursement was recorded in error — put the amount back into what's owed, keep the row as the audit trail. */
    public function reverseDisbursement(int $disbursementId, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            session()->flash('error', 'A reason is required to reverse a disbursement.');

            return;
        }

        $disbursement = Disbursement::where('landlord_id', $this->landlord->id)->findOrFail($disbursementId);
        app(DisbursementService::class)->reverse($disbursement, Auth::user(), $reason);

        session()->flash('status', 'Disbursement reversed — the amount is owed again.');
    }

    /** Admin-only: the transfer was recorded but never actually completed — same effect as reverseDisbursement(), distinct status for reporting. */
    public function markFailed(int $disbursementId, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            session()->flash('error', 'A reason is required to mark a disbursement as failed.');

            return;
        }

        $disbursement = Disbursement::where('landlord_id', $this->landlord->id)->findOrFail($disbursementId);
        app(DisbursementService::class)->markFailed($disbursement, Auth::user(), $reason);

        session()->flash('status', 'Disbursement marked as failed — the amount is owed again.');
    }

    public function render()
    {
        $service = app(DisbursementService::class);

        $disbursements = Disbursement::where('landlord_id', $this->landlord->id)
            ->with(['recordedBy', 'confirmedBy', 'disputedBy', 'reversedBy', 'documents'])
            ->latest('disbursed_at')
            ->latest('id')
            ->get();

        return view('livewire.admin.disbursements.show', [
            'disbursements' => $disbursements,
            'collected' => $service->collectedViaMpesa($this->landlord->id),
            'disbursed' => $service->totalDisbursed($this->landlord->id),
            'owed' => $service->owed($this->landlord->id),
            'awaitingTotal' => $disbursements->where('status', 'awaiting_landlord_confirmation')->sum('amount'),
            'confirmedTotal' => $disbursements->where('status', 'confirmed')->sum('amount'),
            'disputedTotal' => $disbursements->where('status', 'disputed')->sum('amount'),
        ]);
    }
}
