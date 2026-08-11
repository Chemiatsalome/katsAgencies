<?php

namespace App\Livewire\Maintenance;

use App\Models\Complaint;
use App\Models\House;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\Vendor;
use App\Services\MaintenanceCostService;
use App\Services\NotificationService;
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
    public string $tab = 'dashboard';

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public int $perPage = 25;

    // New task form
    public bool $showTaskForm = false;

    public ?int $task_property_id = null;

    public ?int $task_house_id = null;

    public string $task_title = '';

    public string $task_category = 'general';

    public string $task_description = '';

    public string $task_priority = 'medium';

    // Assign / schedule modal
    public ?int $activeTaskId = null;

    public bool $showAssignForm = false;

    public ?int $assign_vendor_id = null;

    public string $assign_scheduled_date = '';

    public string $assign_scheduled_time = '';

    // Completion modal
    public bool $showCompleteForm = false;

    public string $complete_actual_cost = '';

    // Vendor form
    public bool $showVendorForm = false;

    public string $vendor_name = '';

    public string $vendor_company = '';

    public string $vendor_phone = '';

    public string $vendor_email = '';

    public string $vendor_category = '';

    public string $vendor_rate = '';

    public function updatedTaskPropertyId(): void
    {
        $this->task_house_id = null;
    }

    public function openTaskForm(): void
    {
        $this->reset(['task_property_id', 'task_house_id', 'task_title', 'task_description']);
        $this->task_category = 'general';
        $this->task_priority = 'medium';
        $this->showTaskForm = true;
    }

    public function createTask(): void
    {
        $this->authorize('create', MaintenanceTask::class);

        $data = $this->validate([
            'task_property_id' => ['required', 'exists:properties,id'],
            'task_house_id' => ['nullable', 'exists:houses,id'],
            'task_title' => ['required', 'string', 'max:255'],
            'task_category' => ['required', 'string', 'max:255'],
            'task_description' => ['nullable', 'string'],
            'task_priority' => ['required', 'in:low,medium,high,emergency'],
        ]);

        $property = Property::findOrFail($data['task_property_id']);
        $this->authorize('update', $property);

        MaintenanceTask::create([
            'property_id' => $property->id,
            'house_id' => $data['task_house_id'] ?: null,
            'block_id' => $data['task_house_id'] ? House::find($data['task_house_id'])->block_id : null,
            'title' => $data['task_title'],
            'category' => $data['task_category'],
            'description' => $data['task_description'],
            'priority' => $data['task_priority'],
            'status' => 'submitted',
            'created_by' => Auth::id(),
        ]);

        $this->showTaskForm = false;
        session()->flash('status', 'Maintenance task logged.');
    }

    public function convertToTask(int $complaintId): void
    {
        $complaint = Complaint::findOrFail($complaintId);
        $this->authorize('update', $complaint);

        $propertyId = $complaint->property_id ?: $complaint->house?->block->property_id;

        MaintenanceTask::create([
            'property_id' => $propertyId,
            'block_id' => $complaint->house?->block_id,
            'house_id' => $complaint->house_id,
            'complaint_id' => $complaint->id,
            'title' => $complaint->subject,
            'category' => $complaint->category,
            'description' => $complaint->description,
            'priority' => $complaint->priority,
            'status' => 'submitted',
            'created_by' => Auth::id(),
        ]);

        $complaint->update(['status' => 'assigned']);

        $this->tab = 'scheduled';
        session()->flash('status', 'Converted to maintenance task.');
    }

    public function markReviewed(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        $this->authorize('update', $task);
        $task->update(['status' => 'reviewed']);
        $this->notifyTenantOfStatusChange($task, 'Your maintenance request is now under review.');
    }

    public function startProgress(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        $this->authorize('update', $task);
        $task->update(['status' => 'in_progress']);
        $this->notifyTenantOfStatusChange($task, 'Work has started on your maintenance request.');
    }

    public function closeTask(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        $this->authorize('update', $task);
        $task->update(['status' => 'closed']);
        $this->notifyTenantOfStatusChange($task, 'Your maintenance request has been closed.');
    }

    private function notifyTenantOfStatusChange(MaintenanceTask $task, string $message): void
    {
        $complaint = $task->complaint;

        if (! $complaint?->tenant) {
            return;
        }

        app(NotificationService::class)->notify(
            $complaint->tenant,
            'maintenance_status_'.$task->status,
            'Maintenance Request Update',
            $message,
            ['in_app'],
            $task,
        );
    }

    public function openAssignForm(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        $this->authorize('update', $task);

        $this->activeTaskId = $taskId;
        $this->assign_vendor_id = $task->vendor_id;
        $this->assign_scheduled_date = $task->scheduled_date?->format('Y-m-d') ?? '';
        $this->assign_scheduled_time = $task->scheduled_time?->format('H:i') ?? '';
        $this->showAssignForm = true;
    }

    public function saveAssign(): void
    {
        $task = MaintenanceTask::findOrFail($this->activeTaskId);
        $this->authorize('update', $task);

        $data = $this->validate([
            'assign_vendor_id' => ['nullable', 'exists:vendors,id'],
            'assign_scheduled_date' => ['nullable', 'date'],
            'assign_scheduled_time' => ['nullable'],
        ]);

        $status = $data['assign_scheduled_date'] ? 'scheduled' : ($data['assign_vendor_id'] ? 'assigned' : $task->status);

        $task->update([
            'vendor_id' => $data['assign_vendor_id'] ?: null,
            'scheduled_date' => $data['assign_scheduled_date'] ?: null,
            'scheduled_time' => $data['assign_scheduled_time'] ?: null,
            'status' => $status,
        ]);

        if ($status === 'scheduled') {
            $this->notifyTenantOfStatusChange($task, 'Your maintenance request has been scheduled for '.$task->scheduled_date->format('d M Y').'.');
        } elseif ($status === 'assigned') {
            $this->notifyTenantOfStatusChange($task, 'A vendor has been assigned to your maintenance request.');
        }

        $this->showAssignForm = false;
        session()->flash('status', 'Task updated.');
    }

    public function openCompleteForm(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        $this->authorize('update', $task);

        $this->activeTaskId = $taskId;
        $this->complete_actual_cost = $task->estimated_cost ? (string) $task->estimated_cost : '';
        $this->showCompleteForm = true;
    }

    public function complete(): void
    {
        $task = MaintenanceTask::findOrFail($this->activeTaskId);
        $this->authorize('update', $task);

        $data = $this->validate([
            'complete_actual_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $task->update([
            'status' => 'completed',
            'actual_cost' => $data['complete_actual_cost'],
            'completed_at' => now(),
        ]);

        app(MaintenanceCostService::class)->recordExpenseFor($task);

        if ($task->complaint_id) {
            $task->complaint->update(['status' => 'resolved', 'resolved_at' => now()]);
        }

        $this->notifyTenantOfStatusChange($task, 'Your maintenance request has been resolved.');

        $this->showCompleteForm = false;
        session()->flash('status', 'Task marked complete and cost recorded as an expense.');
    }

    public function openVendorForm(): void
    {
        $this->reset(['vendor_name', 'vendor_company', 'vendor_phone', 'vendor_email', 'vendor_category', 'vendor_rate']);
        $this->showVendorForm = true;
    }

    public function saveVendor(): void
    {
        $this->authorize('create', Vendor::class);

        $data = $this->validate([
            'vendor_name' => ['required', 'string', 'max:255'],
            'vendor_company' => ['nullable', 'string', 'max:255'],
            'vendor_phone' => ['required', 'string', 'max:32'],
            'vendor_email' => ['nullable', 'email'],
            'vendor_category' => ['required', 'string', 'max:255'],
            'vendor_rate' => ['nullable', 'string', 'max:255'],
        ]);

        Vendor::create([
            'landlord_id' => Auth::id(),
            'name' => $data['vendor_name'],
            'company' => $data['vendor_company'] ?: null,
            'phone' => $data['vendor_phone'],
            'email' => $data['vendor_email'] ?: null,
            'category' => $data['vendor_category'],
            'rate' => $data['vendor_rate'] ?: null,
        ]);

        $this->showVendorForm = false;
        session()->flash('status', 'Vendor added.');
    }

    public function deleteVendor(int $vendorId): void
    {
        $vendor = Vendor::findOrFail($vendorId);
        $this->authorize('delete', $vendor);
        $vendor->delete();
    }

    public function render()
    {
        $landlordId = Auth::id();
        $properties = Property::forLandlord($landlordId)->orderBy('name')->get();

        $tasksQuery = MaintenanceTask::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->with(['property', 'house', 'vendor']);

        $data = [
            'properties' => $properties,
            'houses' => $this->task_property_id ? House::whereHas('block', fn ($q) => $q->where('property_id', $this->task_property_id))->orderBy('house_number')->get() : collect(),
        ];

        if ($this->tab === 'dashboard') {
            $data['openCount'] = (clone $tasksQuery)->open()->count();
            $data['emergencyCount'] = (clone $tasksQuery)->open()->where('priority', 'emergency')->count();
            $data['scheduledTodayCount'] = (clone $tasksQuery)->scheduledOn(now()->toDateString())->count();
            $data['overdueScheduleCount'] = MaintenanceSchedule::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))->due()->count();
            $data['recentOpenTasks'] = (clone $tasksQuery)->open()->orderByDesc('priority')->latest()->limit(8)->get();
        }

        if ($this->tab === 'requests') {
            $data['complaints'] = Complaint::where(function ($q) use ($landlordId) {
                $q->whereHas('property', fn ($q2) => $q2->where('landlord_id', $landlordId))
                    ->orWhereHas('house.block.property', fn ($q2) => $q2->where('landlord_id', $landlordId));
            })
                ->unresolved()
                ->with('tenant', 'house.block.property', 'property')
                ->orderByDesc('priority')
                ->latest()
                ->get();
        }

        if ($this->tab === 'scheduled') {
            $query = clone $tasksQuery;

            if ($this->status === 'open') {
                $query->open();
            } elseif ($this->status !== 'all') {
                $query->where('status', $this->status);
            }

            if ($this->propertyId !== 'all') {
                $query->where('property_id', (int) $this->propertyId);
            }

            $data['tasks'] = $query->orderByDesc('priority')->latest()->paginate($this->perPage);
            $data['vendors'] = Vendor::where('landlord_id', $landlordId)->orderBy('name')->get();
        }

        if ($this->tab === 'calendar') {
            $data['agenda'] = (clone $tasksQuery)
                ->whereNotNull('scheduled_date')
                ->whereBetween('scheduled_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->orderBy('scheduled_date')
                ->get()
                ->groupBy(fn (MaintenanceTask $t) => $t->scheduled_date->format('Y-m-d'));
        }

        if ($this->tab === 'vendors') {
            $data['vendors'] = Vendor::where('landlord_id', $landlordId)->withCount('maintenanceTasks')->orderBy('name')->get();
        }

        return view('livewire.maintenance.index', $data);
    }
}
