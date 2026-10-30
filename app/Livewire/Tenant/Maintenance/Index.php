<?php

namespace App\Livewire\Tenant\Maintenance;

use App\Models\Complaint;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant')]
class Index extends Component
{
    use WithPagination;

    /** Maps the internal task/complaint status vocabulary onto the tenant-facing statuses from the spec. */
    public const STATUS_MAP = [
        'submitted' => 'submitted',
        'reviewed' => 'under_review',
        'assigned' => 'under_review',
        'scheduled' => 'scheduled',
        'in_progress' => 'in_progress',
        'completed' => 'resolved',
        'resolved' => 'resolved',
        'closed' => 'closed',
    ];

    public function render()
    {
        $tenant = Auth::user()->tenant;

        $complaints = Complaint::where('tenant_id', $tenant?->id ?? 0)
            ->with('maintenanceTask', 'house.block.property')
            ->withCount('updates')
            ->latest()
            ->paginate(15);

        return view('livewire.tenant.maintenance.index', [
            'complaints' => $complaints,
            'hasActiveTenancy' => (bool) $tenant?->tenancies()->active()->exists(),
        ]);
    }
}
