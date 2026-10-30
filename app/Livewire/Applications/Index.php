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

    // ApplicationPolicy::viewAny() passes for any manager — the
    // landlord-granted applications.view permission is the real gate, same
    // as Contracts/Arrears.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasManagerPermission('applications.view'), 403);
    }

    public function render()
    {
        $user = Auth::user();

        $query = Application::with(['property', 'house'])
            ->accessibleBy($user)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest('application_date');

        $statusCounts = Application::accessibleBy($user)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('livewire.applications.index', [
            'applications' => $query->paginate($this->perPage),
            'statusCounts' => $statusCounts,
        ]);
    }
}
