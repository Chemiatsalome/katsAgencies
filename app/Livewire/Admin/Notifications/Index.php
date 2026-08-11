<?php

namespace App\Livewire\Admin\Notifications;

use App\Models\AppNotification;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $channel = 'all';

    public string $status = 'all';

    public function render()
    {
        $query = AppNotification::with('notifiable')
            ->when($this->channel !== 'all', fn ($q) => $q->where('channel', $this->channel))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest();

        return view('livewire.admin.notifications.index', [
            'notifications' => $query->paginate(25),
        ]);
    }
}
