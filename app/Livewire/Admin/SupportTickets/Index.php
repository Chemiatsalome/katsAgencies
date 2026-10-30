<?php

namespace App\Livewire\Admin\SupportTickets;

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
    public string $status = 'open';

    public ?int $expandedTicketId = null;

    public string $replyBody = '';

    public string $resolveNote = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SupportTicket::class);
    }

    public function toggleExpand(int $ticketId): void
    {
        $this->expandedTicketId = $this->expandedTicketId === $ticketId ? null : $ticketId;
        $this->replyBody = '';
        $this->resolveNote = '';
    }

    public function reply(int $ticketId): void
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        $this->authorize('update', $ticket);

        $this->validate(['replyBody' => ['required', 'string', 'max:5000']]);

        app(SupportTicketService::class)->reply($ticket, Auth::user(), $this->replyBody);

        $this->replyBody = '';
        session()->flash('status', 'Reply sent.');
    }

    public function resolve(int $ticketId): void
    {
        $ticket = SupportTicket::findOrFail($ticketId);
        $this->authorize('update', $ticket);

        app(SupportTicketService::class)->resolve($ticket, Auth::user(), $this->resolveNote ?: null);

        $this->resolveNote = '';
        session()->flash('status', 'Ticket marked resolved.');
    }

    public function render()
    {
        $query = SupportTicket::with('landlord', 'replies.user')
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest();

        return view('livewire.admin.support-tickets.index', [
            'tickets' => $query->get(),
        ]);
    }
}
