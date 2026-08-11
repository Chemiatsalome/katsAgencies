<?php

namespace App\Livewire\Admin\AuditLogs;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $causerFilter = 'all';

    public string $subjectType = 'all';

    public function render()
    {
        $query = Activity::with(['causer', 'subject'])
            ->when($this->causerFilter !== 'all', fn ($q) => $q->whereHasMorph('causer', [User::class], fn ($q2) => $q2->role($this->causerFilter)))
            ->when($this->subjectType !== 'all', fn ($q) => $q->where('subject_type', $this->subjectType))
            ->latest();

        return view('livewire.admin.audit-logs.index', [
            'entries' => $query->paginate(30),
            'subjectTypes' => Activity::query()->distinct()->pluck('subject_type')->filter(),
        ]);
    }
}
