<?php

namespace App\Livewire\Tenant\Maintenance;

use App\Models\Complaint;
use App\Models\ComplaintUpdate;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Show extends Component
{
    public Complaint $complaint;

    public string $replyNote = '';

    public string $reopenReason = '';

    public bool $showReopenForm = false;

    public string $recurringNote = '';

    public bool $showRecurringForm = false;

    public function mount(Complaint $complaint): void
    {
        $this->authorize('view', $complaint);
        $this->complaint = $complaint;
    }

    private function landlord()
    {
        return $this->complaint->house
            ? $this->complaint->house->block->property->landlord
            : $this->complaint->property->landlord;
    }

    /**
     * A reply is just a comment on the existing status, not a status
     * change — status_from/status_to are recorded equal so this shows up
     * as "a note" in the thread rather than implying the tenant moved their
     * own request to a different state.
     */
    public function reply(): void
    {
        $this->authorize('view', $this->complaint);
        abort_if($this->complaint->isClosed(), 403, 'This conversation is closed. Report a recurring issue instead if it\'s come back.');

        $this->validate([
            'replyNote' => ['required', 'string', 'max:2000'],
        ]);

        ComplaintUpdate::create([
            'complaint_id' => $this->complaint->id,
            'user_id' => Auth::id(),
            'status_from' => $this->complaint->status,
            'status_to' => $this->complaint->status,
            'note' => $this->replyNote,
        ]);

        app(NotificationService::class)->notify(
            $this->landlord(),
            'complaint_update_posted',
            'New Reply on a Maintenance Request',
            "{$this->complaint->tenant->name} replied on \"{$this->complaint->subject}\": {$this->replyNote}",
            ['in_app'],
            $this->complaint,
        );

        $this->reset('replyNote');
        session()->flash('status', 'Your reply has been sent.');
    }

    /**
     * The landlord marking a task "completed" never meant the tenant agreed
     * it actually fixed anything — this is that missing confirmation. Once
     * confirmed, the request moves to 'closed': the conversation is done,
     * replies are no longer accepted, and it drops into history rather than
     * staying mixed in with active requests.
     */
    public function confirmResolved(): void
    {
        $this->authorize('view', $this->complaint);
        abort_unless($this->complaint->awaitingTenantConfirmation(), 404);

        $this->complaint->update(['tenant_confirmed_at' => now(), 'status' => 'closed']);

        ComplaintUpdate::create([
            'complaint_id' => $this->complaint->id,
            'user_id' => Auth::id(),
            'status_from' => 'resolved',
            'status_to' => 'closed',
            'note' => 'Tenant confirmed the work was completed. Request closed.',
        ]);

        app(NotificationService::class)->notify(
            $this->landlord(),
            'complaint_confirmed_resolved',
            'Tenant Confirmed a Request Resolved',
            "{$this->complaint->tenant->name} confirmed \"{$this->complaint->subject}\" was fixed. The request is now closed.",
            ['in_app'],
            $this->complaint,
        );

        session()->flash('status', 'Thanks for confirming. This request is now closed.');
    }

    public function openRecurringForm(): void
    {
        $this->recurringNote = '';
        $this->showRecurringForm = true;
    }

    public function cancelRecurringForm(): void
    {
        $this->reset(['recurringNote', 'showRecurringForm']);
    }

    /**
     * The issue came back after a closed request — rather than mutating
     * that closed request's own history, this files a brand-new complaint
     * linked back to it, so the original's conversation and confirmation
     * stay exactly as they were.
     */
    public function reportRecurringIssue(): void
    {
        $this->authorize('view', $this->complaint);
        $this->authorize('create', Complaint::class);
        abort_unless($this->complaint->isClosed(), 404);

        $this->validate([
            'recurringNote' => ['required', 'string', 'max:2000'],
        ]);

        $followUp = Complaint::create([
            'tenant_id' => $this->complaint->tenant_id,
            'house_id' => $this->complaint->house_id,
            'property_id' => $this->complaint->property_id,
            'category' => $this->complaint->category,
            'subject' => 'Recurring: '.$this->complaint->subject,
            'description' => $this->recurringNote,
            'priority' => $this->complaint->priority,
            'status' => 'submitted',
            'submitted_by' => Auth::id(),
            'related_complaint_id' => $this->complaint->id,
        ]);

        app(NotificationService::class)->notify(
            $this->landlord(),
            'complaint_submitted',
            'Recurring Maintenance Issue Reported',
            "{$this->complaint->tenant->name} reported that \"{$this->complaint->subject}\" has come back.",
            ['in_app', 'email'],
            $followUp,
        );

        $this->redirectRoute('tenant.maintenance.show', $followUp, navigate: true);
    }

    public function openReopenForm(): void
    {
        $this->reopenReason = '';
        $this->showReopenForm = true;
    }

    public function cancelReopenForm(): void
    {
        $this->reset(['reopenReason', 'showReopenForm']);
    }

    /**
     * Not actually fixed — undoes the completion on both the complaint and
     * its task so the landlord's Work Orders list shows it as back in
     * progress rather than it only reappearing as a confusing duplicate
     * entry in Requests. Does not touch any expense already recorded
     * against the task — that's a financial record the landlord should
     * correct deliberately, not something a reopen should silently reverse.
     */
    public function reopenComplaint(): void
    {
        $this->authorize('view', $this->complaint);
        abort_unless($this->complaint->awaitingTenantConfirmation(), 404);

        $this->validate([
            'reopenReason' => ['required', 'string', 'max:2000'],
        ]);

        $this->complaint->update(['status' => 'assigned', 'resolved_at' => null]);
        $this->complaint->maintenanceTask?->update(['status' => 'in_progress']);

        ComplaintUpdate::create([
            'complaint_id' => $this->complaint->id,
            'user_id' => Auth::id(),
            'status_from' => 'resolved',
            'status_to' => 'assigned',
            'note' => $this->reopenReason,
        ]);

        app(NotificationService::class)->notify(
            $this->landlord(),
            'complaint_reopened',
            'Tenant Says This Is Not Resolved',
            "{$this->complaint->tenant->name} reopened \"{$this->complaint->subject}\": {$this->reopenReason}",
            ['in_app', 'email'],
            $this->complaint,
        );

        $this->cancelReopenForm();
        session()->flash('status', 'We\'ve let your landlord know this still needs attention.');
    }

    public function render()
    {
        $this->complaint->load(['updates.user', 'maintenanceTask', 'documents', 'house.block.property', 'relatedComplaint', 'followUps']);

        return view('livewire.tenant.maintenance.show', [
            'updates' => $this->complaint->updates->sortBy('created_at'),
        ]);
    }
}
