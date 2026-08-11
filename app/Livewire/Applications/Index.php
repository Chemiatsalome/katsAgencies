<?php

namespace App\Livewire\Applications;

use App\Models\Application;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    #[Url]
    public int $perPage = 25;

    public function render()
    {
        $landlordId = Auth::id();

        $query = Application::with(['property', 'house'])
            ->whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest('application_date');

        return view('livewire.applications.index', [
            'applications' => $query->paginate($this->perPage),
        ]);
    }
}
