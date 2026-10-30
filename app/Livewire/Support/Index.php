<?php

namespace App\Livewire\Support;

use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
class Index extends Component
{
    #[Url]
    public string $status = 'all';

    public bool $showNewForm = false;

    public string $category = 'other';

    public string $subject = '';

    public string $message = '';

    public ?int $expandedTicketId = null;

    public string $replyBody = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('landlord'), 403);
    }

    public function openNewForm(): void
    {
        $this->reset(['category', 'subject', 'message']);
        $this->category = 'other';
        $this->showNewForm = true;
    }

    public function cancelNewForm(): void
    {
        $this->showNewForm = false;
    }

    public function submit(): void
    {
        $data = $this->validate([
            'category' => ['required', 'in:'.implode(',', SupportTicket::CATEGORIES)],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        app(SupportTicketService::class)->create(Auth::user(), $data['category'], $data['subject'], $data['message']);

        $this->showNewForm = false;
        session()->flash('status', 'Support ticket submitted — an admin will respond soon.');
    }

    public function toggleExpand(int $ticketId): void
    {
        $this->expandedTicketId = $this->expandedTicketId === $ticketId ? null : $ticketId;
        $this->replyBody = '';
    }

    public function reply(int $ticketId): void
    {
        $ticket = SupportTicket::where('landlord_id', Auth::id())->findOrFail($ticketId);

        $this->validate(['replyBody' => ['required', 'string', 'max:5000']]);

        app(SupportTicketService::class)->reply($ticket, Auth::user(), $this->replyBody);

        $this->replyBody = '';
        session()->flash('status', 'Reply sent.');
    }

    public function render()
    {
        $query = SupportTicket::where('landlord_id', Auth::id())
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->with('replies.user')
            ->latest();

        return view('livewire.support.index', [
            'tickets' => $query->get(),
        ]);
    }
}
