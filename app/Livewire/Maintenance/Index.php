<?php

namespace App\Livewire\Maintenance;

use App\Models\Complaint;
use App\Models\ComplaintUpdate;
use App\Models\House;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Vendor;
use App\Services\MaintenanceCostService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use RuntimeException;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    // orderByDesc('priority') sorts alphabetically (medium, low, high,
    // emergency — emergency last), the opposite of urgency order. This CASE
    // expression is portable across MySQL and SQLite, unlike FIELD().
    private const PRIORITY_ORDER_SQL = "CASE priority WHEN 'emergency' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END";

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

    public string $assign_estimated_cost = '';

    public bool $assign_record_expense_now = false;

    // Completion modal
    public bool $showCompleteForm = false;

    public string $complete_actual_cost = '';

    public string $complete_additional_cost = '';

    // Direct maintenance/expense deposit charging: replaces the old plain
    // checkbox — 'landlord' (default) or 'tenant'. Selecting 'tenant' is
    // itself the liability decision; there is no separate step afterward.
    public string $complete_cost_responsibility = 'landlord';

    public ?int $complete_liability_tenancy_id = null;

    public string $complete_deduction_amount = '';

    public string $complete_deduction_reason = '';

    public $complete_deduction_evidence;

    // Post-confirmation deduction (for a completed task tied to a
    // complaint — only reachable once the tenant has confirmed the fix;
    // see MaintenanceCostService::canDeductFromDeposit()). Kept only as a
    // narrow legacy fallback for a decision made before this phase that has
    // no deduction yet — the primary path is now confirmTenantLiability()
    // below, which decides and deducts together.
    public ?int $deductingTaskId = null;

    public $deductingTaskEvidence;

    // Liability decision + deduction modal — direct maintenance/expense
    // deposit charging merges what used to be two separate steps
    // (Decide Liability, then Deduct from Deposit) into one.
    public ?int $decidingTaskId = null;

    public ?int $liability_tenancy_id = null;

    public string $liability_reason = '';

    public string $liability_deduction_amount = '';

    public $liability_deduction_evidence;

    // Vendor form
    public bool $showVendorForm = false;

    public string $vendor_name = '';

    public string $vendor_company = '';

    public string $vendor_phone = '';

    public string $vendor_email = '';

    public string $vendor_category = '';

    public string $vendor_rate = '';

    // Complaint thread modal
    public ?int $activeComplaintId = null;

    public bool $showComplaintThread = false;

    public string $complaintNote = '';

    /**
     * A reply/status notification links straight to the specific request
     * via ?complaint=123 — this is what actually opens its thread modal on
     * arrival, rather than just landing on the Requests tab with nothing
     * selected.
     */
    // MaintenanceTaskPolicy/ComplaintPolicy's viewAny() both pass for any
    // manager — this page covers both tasks and complaints, so either of the
    // two landlord-granted view permissions is enough to reach it, same as
    // Contracts/Arrears.
    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user->hasManagerPermission('maintenance.view') || $user->hasManagerPermission('complaints.view'), 403);

        $complaintId = request()->integer('complaint');

        if (! $complaintId) {
            return;
        }

        $complaint = Complaint::find($complaintId);

        if ($complaint && Auth::user()->can('view', $complaint)) {
            $this->tab = $complaint->isClosed() ? 'history' : 'requests';
            $this->activeComplaintId = $complaintId;
            $this->showComplaintThread = true;
        }
    }

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

        $task = MaintenanceTask::create([
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

        // Phase 7B, finding 4: a landlord/manager logging a task directly
        // (not via a tenant's own complaint) never notified anyone at all —
        // for an ordinary task that's fine (nothing's happened yet from the
        // tenant's side), but for one logged at emergency priority, the
        // tenant living there has no other way to find out their landlord
        // already knows and is acting on it. Scoped tightly to emergency +
        // a specific house (a property-wide task has no single tenant to
        // notify, same as every other call site here); never fires for
        // low/medium/high, which is unchanged, existing behavior.
        if ($task->priority === 'emergency' && $task->house_id) {
            $this->notifyTenantOfStatusChange(
                $task,
                "Your landlord has logged an emergency maintenance issue for your unit: {$task->title}. They are aware and will be in touch shortly.",
                ['in_app', 'email'],
            );
        }

        $this->showTaskForm = false;
        session()->flash('status', 'Maintenance task logged.');
    }

    public function convertToTask(int $complaintId): void
    {
        $complaint = Complaint::findOrFail($complaintId);
        $this->authorize('update', $complaint);
        // Phase 7A, F9: this creates a MaintenanceTask, which is a distinct
        // action from "can respond to this complaint" — a manager granted
        // complaints.respond but not maintenance.create used to be able to
        // create tasks through this path alone, bypassing the permission
        // the landlord specifically withheld. Both authorizations must pass
        // before anything is read or written below.
        $this->authorize('create', MaintenanceTask::class);

        // Without this, a double-click (or any repeated request before the
        // button's disabled state takes effect) created two identical
        // tasks for the same complaint — reproduced live: two "issue" rows,
        // same millisecond timestamp. A complaint only ever sits at
        // "submitted" before its first conversion; this method itself is
        // the only thing that ever moves it to "assigned", so the second
        // click just finds it already converted and no-ops.
        if ($complaint->status !== 'submitted') {
            $this->tab = 'scheduled';

            return;
        }

        $propertyId = $complaint->property_id ?: $complaint->house?->block->property_id;

        MaintenanceTask::create([
            'property_id' => $propertyId,
            'block_id' => $complaint->house?->block_id,
            'house_id' => $complaint->house_id,
            // §8: inherited from the complaint, never re-derived.
            'tenancy_id' => $complaint->tenancy_id,
            'complaint_id' => $complaint->id,
            'title' => $complaint->subject,
            'category' => $complaint->category,
            'description' => $complaint->description,
            'priority' => $complaint->priority,
            'status' => 'submitted',
            'created_by' => Auth::id(),
        ]);

        $complaint->update(['status' => 'assigned']);
        $this->clearComplaintNotification($complaint);

        $this->tab = 'scheduled';
        session()->flash('status', 'Converted to maintenance task.');
    }

    public function openComplaintThread(int $complaintId): void
    {
        $complaint = Complaint::findOrFail($complaintId);
        $this->authorize('view', $complaint);

        $this->activeComplaintId = $complaintId;
        $this->complaintNote = '';
        $this->showComplaintThread = true;
    }

    public function closeComplaintThread(): void
    {
        $this->reset(['activeComplaintId', 'showComplaintThread', 'complaintNote']);
    }

    /** Mirrors Tenant\Maintenance\Show::reply() on the other side of the same thread — a comment, not a status change. */
    public function postComplaintUpdate(): void
    {
        $complaint = Complaint::with('tenant')->findOrFail($this->activeComplaintId);
        $this->authorize('update', $complaint);
        abort_if($complaint->isClosed(), 403, 'This conversation is closed — the tenant has confirmed the fix.');

        $this->validate([
            'complaintNote' => ['required', 'string', 'max:2000'],
        ]);

        ComplaintUpdate::create([
            'complaint_id' => $complaint->id,
            'user_id' => Auth::id(),
            'status_from' => $complaint->status,
            'status_to' => $complaint->status,
            'note' => $this->complaintNote,
        ]);

        $this->clearComplaintNotification($complaint);

        if ($complaint->tenant) {
            app(NotificationService::class)->notify(
                $complaint->tenant,
                'complaint_update_posted',
                'Update on Your Maintenance Request',
                "Your landlord replied on \"{$complaint->subject}\": {$this->complaintNote}",
                ['in_app'],
                $complaint,
            );
        }

        $this->complaintNote = '';
        session()->flash('status', 'Reply sent.');
    }

    public function markReviewed(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        // Phase 7A, finding 4: a status-progression step toward resolution,
        // not a vendor assignment or an appointment — gated on resolve.
        $this->authorize('resolve', $task);

        if (! in_array($task->status, MaintenanceTask::OPEN_STATUSES, true)) {
            return;
        }

        $task->update(['status' => 'reviewed']);
        $this->clearComplaintNotification($task->complaint);
        $this->notifyTenantOfStatusChange($task, 'Your maintenance request is now under review.');
    }

    public function startProgress(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        // Phase 7A, finding 4: same reasoning as markReviewed() — gated on resolve.
        $this->authorize('resolve', $task);

        if (! in_array($task->status, MaintenanceTask::OPEN_STATUSES, true)) {
            return;
        }

        $task->update(['status' => 'in_progress']);
        $this->clearComplaintNotification($task->complaint);
        $this->notifyTenantOfStatusChange($task, 'Work has started on your maintenance request.');
    }

    public function closeTask(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        // Phase 7A, finding 4: finalizing/closing is resolution authority.
        $this->authorize('resolve', $task);
        $task->update(['status' => 'closed']);
        $this->clearComplaintNotification($task->complaint);
        $this->notifyTenantOfStatusChange($task, 'Your maintenance request has been closed.');
    }

    /**
     * Every landlord-facing maintenance/complaint notification is keyed to
     * the Complaint, never the task (see Tenant\Maintenance\Create/Show) —
     * once the landlord/manager takes any action on it, that alert no
     * longer needs attention, so the generic bell shouldn't keep counting
     * it as unread.
     */
    private function clearComplaintNotification(?Complaint $complaint): void
    {
        if (! $complaint) {
            return;
        }

        $landlord = $complaint->house
            ? $complaint->house->block->property->landlord
            : $complaint->property->landlord;

        app(NotificationService::class)->markRelatedRead($complaint, $landlord);
    }

    /**
     * Resolves to the complaint's tenant when the task came from one, but
     * also to whoever currently lives in the task's house when it didn't —
     * a task the landlord scheduled directly against a unit (no complaint
     * involved) used to notify no one at all, even though it's the tenant
     * who needs to know a vendor is coming. A property-wide task (no
     * house_id) still has no single tenant to notify.
     */
    /**
     * @param  string[]  $channels  Phase 7B, F11: defaults to in_app only,
     *                              unchanged for every existing call site —
     *                              createTask() is the only caller that ever
     *                              passes ['in_app', 'email'], for an
     *                              emergency-priority task it just logged.
     */
    private function notifyTenantOfStatusChange(MaintenanceTask $task, string $message, array $channels = ['in_app']): void
    {
        $tenants = collect([$task->complaint?->tenant]);

        if ($task->house_id) {
            $tenants = $tenants->merge(
                Tenancy::where('house_id', $task->house_id)->active()->with('tenant')->get()->pluck('tenant')
            );
        }

        $tenants = $tenants->filter()->unique('id');

        foreach ($tenants as $tenant) {
            app(NotificationService::class)->notify(
                $tenant,
                'maintenance_status_'.$task->status,
                'Maintenance Request Update',
                $message,
                $channels,
                $task,
            );
        }
    }

    public function openAssignForm(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        // Phase 7A, finding 4: this form edits both the vendor and the
        // schedule — either delegable authority is enough to open it; which
        // one saveAssign() actually requires depends on which field the
        // manager changes.
        abort_unless(Auth::user()->can('assign', $task) || Auth::user()->can('schedule', $task), 403);

        $this->activeTaskId = $taskId;
        $this->assign_vendor_id = $task->vendor_id;
        $this->assign_scheduled_date = $task->scheduled_date?->format('Y-m-d') ?? '';
        $this->assign_scheduled_time = $task->scheduled_time?->format('H:i') ?? '';
        $this->assign_estimated_cost = $task->estimated_cost ? (string) $task->estimated_cost : '';
        $this->assign_record_expense_now = false;
        $this->showAssignForm = true;
    }

    public function saveAssign(): void
    {
        $task = MaintenanceTask::findOrFail($this->activeTaskId);

        // Phase 7A, finding 4: this one form edits three independently
        // delegable things — who's assigned, when it's scheduled, and (via
        // the "record expense now" toggle) an immediate financial entry.
        // Each is gated by exactly the permission that specific change
        // requires, never by one blanket check — a scheduling-only manager
        // touching only the date must never be able to also silently swap
        // out the assigned vendor, and vice versa. Compared against the
        // task's current values (not just whether the submitted value is
        // non-empty) so clearing an existing vendor/date still requires the
        // same authority that setting one would.
        $vendorChanging = (string) ($this->assign_vendor_id ?: '') !== (string) ($task->vendor_id ?: '');
        $scheduleChanging = ($this->assign_scheduled_date ?: '') !== ($task->scheduled_date?->format('Y-m-d') ?: '');

        if ($vendorChanging) {
            $this->authorize('assign', $task);
        }

        if ($scheduleChanging) {
            $this->authorize('schedule', $task);
        }

        if ($this->assign_record_expense_now) {
            // Phase 7A follow-up: immediately records an Expense — cost-
            // recording authority specifically, not operational resolve.
            $this->authorize('manage_costs', $task);
        }

        if (! $vendorChanging && ! $scheduleChanging && ! $this->assign_record_expense_now) {
            // Nothing identity- or schedule-relevant is changing (e.g. only
            // the estimated-cost figure) — still a write to this form, so
            // require at least the narrower of the two authorities it's
            // otherwise about.
            $this->authorize('assign', $task);
        }

        if (! in_array($task->status, MaintenanceTask::OPEN_STATUSES, true)) {
            $this->showAssignForm = false;

            return;
        }

        $data = $this->validate([
            'assign_vendor_id' => ['nullable', 'exists:vendors,id'],
            'assign_scheduled_date' => ['nullable', 'date'],
            'assign_scheduled_time' => ['nullable'],
            'assign_estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $status = $data['assign_scheduled_date'] ? 'scheduled' : ($data['assign_vendor_id'] ? 'assigned' : $task->status);

        $task->update([
            'vendor_id' => $data['assign_vendor_id'] ?: null,
            'scheduled_date' => $data['assign_scheduled_date'] ?: null,
            'scheduled_time' => $data['assign_scheduled_time'] ?: null,
            'estimated_cost' => $data['assign_estimated_cost'] ?: null,
            'status' => $status,
        ]);

        // The second of the two ways a cost can become an expense — the
        // other is the usual "mark complete" flow below. recordExpenseFor()
        // is idempotent on $task->expense_id, so whichever happens first
        // wins and the other is a no-op rather than a duplicate expense.
        if ($this->assign_record_expense_now && $data['assign_estimated_cost']) {
            $task->update(['actual_cost' => $data['assign_estimated_cost']]);
            app(MaintenanceCostService::class)->recordExpenseFor($task);
        }

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
        $task = MaintenanceTask::with('complaint')->findOrFail($taskId);
        // Phase 7A, finding 4: completion records cost/resolution — resolve.
        $this->authorize('resolve', $task);

        $this->activeTaskId = $taskId;
        $this->complete_actual_cost = ($task->actual_cost ?: $task->estimated_cost) ? (string) ($task->actual_cost ?: $task->estimated_cost) : '';
        $this->complete_additional_cost = '';
        $this->complete_cost_responsibility = 'landlord';
        $this->complete_deduction_reason = '';
        $this->complete_deduction_evidence = null;

        // Direct maintenance/expense deposit charging: never guessed for a
        // complaint-linked task — tenant confirmation can't have happened
        // yet at this exact point, so the toggle stays disabled regardless
        // of a candidate (see complete()'s own $chargingTenant gate).
        $this->complete_liability_tenancy_id = ! $task->complaint_id
            ? (app(MaintenanceCostService::class)->candidateTenancyFor($task)['tenancy']?->id)
            : null;
        $this->complete_deduction_amount = $this->complete_actual_cost;

        $this->showCompleteForm = true;
    }

    public function complete(): void
    {
        $task = MaintenanceTask::with('complaint')->findOrFail($this->activeTaskId);
        // Phase 7A, finding 4: matches PermissionSeeder's own documented
        // intent for maintenance.resolve ("mark maintenance resolved").
        $this->authorize('resolve', $task);

        // A task re-completed after the tenant reopened it already has its
        // first expense on record (expense_id set) — re-running
        // recordExpenseFor() there would just no-op and silently swallow
        // whatever it actually cost to fix properly this time. That extra
        // cost needs its own expense row, entered explicitly rather than
        // inferred, since "the same visit cost more" and "a second visit
        // was needed" aren't distinguishable from the task alone.
        $alreadyExpensed = $task->expense_id !== null;

        // Phase 7A follow-up: completing a task always needs operational
        // resolve authority (just checked above), but actually recording a
        // new, non-zero cost — the first one or an additional one — is
        // maintenance.manage_costs' domain specifically. A zero-cost
        // completion records no Expense at all (see recordExpenseFor()'s own
        // guard below), so resolve alone is enough for that case; checked
        // against the raw submitted value before validation runs, same
        // pattern saveAssign() already uses for its own per-field gating.
        $costBeingRecorded = $alreadyExpensed
            ? (float) ($this->complete_additional_cost ?: 0) > 0
            : (float) ($this->complete_actual_cost ?: 0) > 0;

        if ($costBeingRecorded) {
            $this->authorize('manage_costs', $task);
        }

        // Direct maintenance/expense deposit charging: only ever reachable
        // on first completion (a re-completion's additional cost has no
        // back-link to this task at all — see recordAdditionalExpenseFor()
        // — charging it would need its own separate flow, not built here),
        // and never for a complaint-linked task — tenant confirmation
        // cannot have happened yet at the exact moment a task is first
        // marked complete. Once confirmed, confirmTenantLiability() below
        // is the path for charging a complaint-linked task.
        $chargingTenant = ! $alreadyExpensed && ! $task->complaint_id && $this->complete_cost_responsibility === 'tenant';

        if ($chargingTenant) {
            // Do not allow ordinary cost-recording permission to authorize
            // deposit deductions — this is deliberately a separate check,
            // not implied by manage_costs above.
            $this->authorize('manage_liability', $task);
        }

        $rules = $alreadyExpensed ? [
            'complete_additional_cost' => ['nullable', 'numeric', 'min:0'],
        ] : [
            'complete_actual_cost' => ['required', 'numeric', 'min:0'],
            'complete_deduction_evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];

        if ($chargingTenant) {
            $rules += [
                'complete_liability_tenancy_id' => ['required', 'integer', 'exists:tenancies,id'],
                'complete_deduction_amount' => ['required', 'numeric', 'min:0.01'],
                'complete_deduction_reason' => ['required', 'string', 'max:1000'],
            ];
        }

        $data = $this->validate($rules);

        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ] + ($alreadyExpensed
            ? ['actual_cost' => (float) $task->actual_cost + (float) ($data['complete_additional_cost'] ?: 0)]
            : ['actual_cost' => $data['complete_actual_cost']]
        ));

        if ($alreadyExpensed) {
            if ($data['complete_additional_cost']) {
                app(MaintenanceCostService::class)->recordAdditionalExpenseFor($task, (float) $data['complete_additional_cost']);
            }
        } else {
            app(MaintenanceCostService::class)->recordExpenseFor($task);

            // The expense itself is always recorded regardless — only the
            // charge-to-tenant decision+deduction below is conditional and
            // atomic: validated server-side (tenancy ownership via
            // exists:tenancies,id plus the decide()/deductFromTenantDeposit()
            // calls' own re-derivation — never trusting the client-submitted
            // tenancy id beyond "does this row exist"), and never left
            // half-done — if the deduction fails for any reason (e.g. no
            // deposit held), the whole decide+deduct is rolled back
            // together so the expense is never left "marked as tenant-
            // charged" with no actual deduction behind it.
            if ($chargingTenant) {
                try {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($task, $data) {
                        // Server-side tenancy/property association check —
                        // see confirmTenantLiability()'s identical guard.
                        $tenancy = Tenancy::where('house_id', $task->house_id)->whereKey((int) $data['complete_liability_tenancy_id'])->first();

                        if (! $tenancy) {
                            throw new RuntimeException('That tenancy has never occupied this unit — select one from the list.');
                        }

                        $freshTask = $task->fresh('expense');

                        app(\App\Services\TenantLiabilityDecisionService::class)->decide(
                            $freshTask->expense, 'tenant_liable', $tenancy->id, $data['complete_deduction_reason'], Auth::id(),
                        );

                        app(MaintenanceCostService::class)->deductFromTenantDeposit(
                            $freshTask,
                            Auth::id(),
                            $this->complete_deduction_evidence ?: null,
                            (float) $data['complete_deduction_amount'],
                            awaitingTenantAcknowledgement: true,
                            reason: $data['complete_deduction_reason'],
                        );
                    });
                } catch (RuntimeException|\InvalidArgumentException $e) {
                    // The task is still correctly completed above — only the
                    // charge-to-tenant proposal failed. $chargeFailed stops
                    // the optimistic "awaiting the tenant's response" message
                    // below from being shown alongside this real error, since
                    // no deduction (or liability decision) actually exists.
                    $chargeFailed = true;
                    session()->flash('error', "Task completed, but the deposit charge wasn't recorded: {$e->getMessage()}");
                }
            }
        }

        if ($task->complaint_id) {
            $task->complaint->update(['status' => 'resolved', 'resolved_at' => now(), 'tenant_confirmed_at' => null]);
        }

        $this->clearComplaintNotification($task->complaint);
        $this->notifyTenantOfStatusChange($task, 'Your maintenance request has been resolved.');

        $this->showCompleteForm = false;

        if (! ($chargeFailed ?? false)) {
            session()->flash('status', $chargingTenant ? 'Task marked complete. The proposed deposit charge is awaiting the tenant\'s response.' : 'Task marked complete and cost recorded as an expense.');
        }
    }

    /** Every tenancy that has ever occupied this task's house — the override list for the liability decision form. */
    public function everyTenancyForTask(MaintenanceTask $task)
    {
        return $task->house_id
            ? Tenancy::where('house_id', $task->house_id)->with('tenant')->orderByDesc('move_in_date')->get()
            : collect();
    }

    public function openLiabilityDecision(int $taskId): void
    {
        $task = MaintenanceTask::with(['complaint', 'expense'])->findOrFail($taskId);
        // Phase 7A follow-up: liability decisions are their own permission
        // now, separate from operational resolve — a manager must never
        // reach this via scheduling, assignment, cost-recording, or
        // operational resolve authority alone.
        $this->authorize('manage_liability', $task);

        // Integration Phase A: the decision is recorded against this task's
        // linked Expense (the canonical decidable shared with Expenses\Index)
        // — never auto-created here if missing. In practice complete()
        // always calls recordExpenseFor() first when actual_cost > 0 (the
        // only time this button is ever shown), so this should never
        // trigger from the normal flow; kept as a safe refusal rather than
        // assuming that invariant always holds.
        if (! $task->expense) {
            session()->flash('error', 'This task has no recorded cost yet — nothing to decide liability for.');

            return;
        }

        // Preserve the tenant confirmation requirement for complaint-linked
        // maintenance — a landlord opening this modal before the tenant has
        // confirmed the fix would otherwise fill in an amount/reason only
        // to have the deduction refused at the last step. Refused up front
        // instead, mirroring the Work Orders row's own "Awaiting tenant
        // confirmation" label.
        if ($task->complaint_id && ! $task->complaint?->tenant_confirmed_at) {
            session()->flash('error', 'The tenant hasn\'t confirmed this fix yet — wait for their confirmation before charging the deposit.');

            return;
        }

        $this->decidingTaskId = $taskId;
        $candidate = app(MaintenanceCostService::class)->candidateTenancyFor($task);
        $this->liability_tenancy_id = $candidate['tenancy']?->id;
        $this->liability_reason = '';
        $this->liability_deduction_amount = (string) $task->actual_cost;
        $this->liability_deduction_evidence = null;
    }

    public function cancelLiabilityDecision(): void
    {
        $this->reset(['decidingTaskId', 'liability_tenancy_id', 'liability_reason', 'liability_deduction_amount', 'liability_deduction_evidence']);
    }

    /**
     * Direct maintenance/expense deposit charging: decides liability and
     * records the (awaiting-acknowledgement) deduction together, in one
     * transaction — this is now the primary path for charging a completed
     * task to a tenant's deposit, replacing the old two-step Decide-
     * Liability-then-Deduct-from-Deposit flow. openDeductForm()/
     * confirmDeductFromDeposit() remain only as a narrow fallback for a
     * decision that already exists with no deduction yet (e.g. one made
     * before this change shipped).
     */
    public function confirmTenantLiability(): void
    {
        $task = MaintenanceTask::with(['expense', 'complaint'])->findOrFail($this->decidingTaskId);
        // Phase 7A follow-up: same reasoning as openLiabilityDecision() — manage_liability.
        $this->authorize('manage_liability', $task);

        // Integration Phase A: see openLiabilityDecision() — same safe refusal.
        if (! $task->expense) {
            $this->addError('liability_reason', 'This task has no recorded cost yet — nothing to decide liability for.');

            return;
        }

        if ($task->complaint_id && ! $task->complaint?->tenant_confirmed_at) {
            $this->addError('liability_reason', 'The tenant hasn\'t confirmed this fix yet — wait for their confirmation before charging the deposit.');

            return;
        }

        $this->validate([
            'liability_tenancy_id' => ['required', 'integer', 'exists:tenancies,id'],
            'liability_reason' => ['required', 'string', 'max:1000'],
            'liability_deduction_amount' => ['required', 'numeric', 'min:0.01'],
            'liability_deduction_evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($task) {
                // Server-side tenancy ownership/property association check —
                // exists:tenancies,id above only proves the row exists
                // somewhere, not that it's related to this task at all.
                // Scoped exactly like everyTenancyForTask()'s own dropdown:
                // only a tenancy that has actually occupied this task's own
                // house is ever accepted, regardless of what the client
                // submits — a tenant at a different property (even one the
                // acting landlord/manager legitimately owns) is refused.
                $tenancy = Tenancy::where('house_id', $task->house_id)->whereKey((int) $this->liability_tenancy_id)->first();

                if (! $tenancy) {
                    throw new RuntimeException('That tenancy has never occupied this unit — select one from the list.');
                }

                app(\App\Services\TenantLiabilityDecisionService::class)->decide(
                    $task->expense, 'tenant_liable', $tenancy->id, $this->liability_reason, Auth::id(),
                );

                app(MaintenanceCostService::class)->deductFromTenantDeposit(
                    $task->fresh(),
                    Auth::id(),
                    $this->liability_deduction_evidence ?: null,
                    (float) $this->liability_deduction_amount,
                    awaitingTenantAcknowledgement: true,
                    reason: $this->liability_reason,
                );
            });
        } catch (RuntimeException|\InvalidArgumentException $e) {
            $this->addError('liability_reason', $e->getMessage());

            return;
        }

        $this->cancelLiabilityDecision();
        session()->flash('status', 'The proposed deposit charge is awaiting the tenant\'s response.');
    }

    public function markLandlordResponsible(): void
    {
        $task = MaintenanceTask::with('expense')->findOrFail($this->decidingTaskId);
        // Phase 7A follow-up: same reasoning as openLiabilityDecision() — manage_liability.
        $this->authorize('manage_liability', $task);

        // Integration Phase A: see openLiabilityDecision() — same safe refusal.
        if (! $task->expense) {
            $this->addError('liability_reason', 'This task has no recorded cost yet — nothing to decide liability for.');

            return;
        }

        $this->validate(['liability_reason' => ['required', 'string', 'max:1000']]);

        try {
            app(\App\Services\TenantLiabilityDecisionService::class)->decide(
                $task->expense, 'landlord_responsible', null, $this->liability_reason, Auth::id(),
            );
        } catch (RuntimeException $e) {
            $this->addError('liability_reason', $e->getMessage());

            return;
        }

        $this->cancelLiabilityDecision();
        session()->flash('status', 'Marked as a landlord responsibility.');
    }

    public function openDeductForm(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        // Phase 7A follow-up: initiating a deposit deduction is its own
        // liability-management permission now.
        $this->authorize('manage_liability', $task);

        $this->deductingTaskId = $taskId;
        $this->deductingTaskEvidence = null;
    }

    public function cancelDeductForm(): void
    {
        $this->reset(['deductingTaskId', 'deductingTaskEvidence']);
    }

    public function confirmDeductFromDeposit(): void
    {
        $task = MaintenanceTask::with('complaint')->findOrFail($this->deductingTaskId);
        // Phase 7A follow-up: same reasoning as openDeductForm() — manage_liability.
        $this->authorize('manage_liability', $task);

        $this->validate([
            'deductingTaskEvidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        try {
            app(MaintenanceCostService::class)->deductFromTenantDeposit(
                $task,
                Auth::id(),
                $this->deductingTaskEvidence ?: null,
            );
        } catch (RuntimeException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
            $this->cancelDeductForm();

            return;
        }

        $this->cancelDeductForm();
        session()->flash('status', 'Deducted from the tenant\'s security deposit.');
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
        $user = Auth::user();
        $landlordId = $user->landlordId();
        $properties = Property::accessibleBy($user)->orderBy('name')->get();

        $tasksQuery = MaintenanceTask::accessibleBy($user)
            ->with(['property', 'house', 'vendor', 'complaint', 'expense', 'depositDeduction']);

        $data = [
            'properties' => $properties,
            'houses' => $this->task_property_id ? House::whereHas('block', fn ($q) => $q->where('property_id', $this->task_property_id))->accessibleBy($user)->orderBy('house_number')->get() : collect(),
            'activeComplaint' => null,
            'complaintUpdates' => collect(),
            'completingTaskHasExpense' => false,
            'completingTaskPreviousCost' => null,
        ];

        if ($this->showCompleteForm && ($completingTask = MaintenanceTask::with('complaint')->find($this->activeTaskId))) {
            $data['completingTaskHasExpense'] = $completingTask->expense_id !== null;
            $data['completingTaskPreviousCost'] = $completingTask->actual_cost;

            // Direct maintenance/expense deposit charging: a task tied to a
            // tenant's own complaint can never be charged at this exact
            // point — tenant_confirmed_at cannot have happened yet for a
            // task still being marked complete for the first time. The
            // toggle stays visible but disabled, with an explanation; once
            // confirmed, confirmTenantLiability() from the Work Orders list
            // is the path for charging it.
            $data['completingTaskComplaintLinked'] = (bool) $completingTask->complaint_id;

            // Preselect only when candidateTenancyFor() resolves to exactly
            // one tenancy — never for 'none'/'multiple', which would
            // otherwise invite guessing who to charge. The landlord can
            // always pick a different one from the full occupancy history
            // below regardless.
            $data['completingTaskCandidateTenancies'] = $completingTask->complaint_id ? collect() : $this->everyTenancyForTask($completingTask);
            $data['completingTaskSelectedTenancy'] = $this->complete_liability_tenancy_id
                ? $data['completingTaskCandidateTenancies']->firstWhere('id', $this->complete_liability_tenancy_id)
                : null;
        }

        // A complaint the tenant just submitted, not yet converted into a
        // task, used to be invisible here — "Open Tasks: 0" and an empty
        // "Needs Attention" looked like nothing was pending even while a
        // brand-new, un-triaged request was sitting on the Requests tab.
        $unconvertedComplaintsQuery = Complaint::accessibleBy($user)->unresolved()->whereDoesntHave('maintenanceTask');

        if ($this->tab === 'dashboard') {
            $openTasksCount = (clone $tasksQuery)->open()->count();
            $unconvertedCount = (clone $unconvertedComplaintsQuery)->count();

            $data['openCount'] = $openTasksCount + $unconvertedCount;
            $data['emergencyCount'] = (clone $tasksQuery)->open()->where('priority', 'emergency')->count()
                + (clone $unconvertedComplaintsQuery)->where('priority', 'emergency')->count();
            $data['awaitingConfirmationCount'] = (clone $tasksQuery)
                ->where('status', 'completed')
                ->whereHas('complaint', fn ($q) => $q->whereNull('tenant_confirmed_at'))
                ->count();
            $data['scheduledTodayCount'] = (clone $tasksQuery)->scheduledOn(now()->toDateString())->count();
            $data['overdueScheduleCount'] = MaintenanceSchedule::accessibleBy($user)->due()->count();

            $openTasks = (clone $tasksQuery)->open()->orderByRaw(self::PRIORITY_ORDER_SQL)->latest()->limit(8)->get()
                ->map(fn (MaintenanceTask $t) => (object) [
                    'type' => 'task',
                    'id' => $t->id,
                    'label' => $t->title,
                    'propertyLabel' => $t->property->name.($t->house ? ', '.$t->house->house_number : ''),
                    'priority' => $t->priority,
                    'status' => $t->status,
                ]);

            $unconvertedComplaints = (clone $unconvertedComplaintsQuery)->with('property', 'house.block.property')->orderByRaw(self::PRIORITY_ORDER_SQL)->latest()->limit(8)->get()
                ->map(fn (Complaint $c) => (object) [
                    'type' => 'complaint',
                    'id' => $c->id,
                    'label' => $c->subject,
                    'propertyLabel' => $c->property?->name ?? $c->house?->block->property->name ?? '',
                    'priority' => $c->priority,
                    'status' => $c->status,
                ]);

            $data['recentOpenTasks'] = $unconvertedComplaints->concat($openTasks)
                ->sortBy(fn ($item) => match ($item->priority) {
                    'emergency' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, default => 4,
                })
                ->take(8)
                ->values();
        }

        if ($this->tab === 'requests') {
            $data['complaints'] = Complaint::accessibleBy($user)
                ->unresolved()
                ->with('tenant', 'house.block.property', 'property', 'maintenanceTask')
                ->withCount('updates')
                ->orderByRaw(self::PRIORITY_ORDER_SQL)
                ->latest()
                ->get();
        }

        if ($this->tab === 'history') {
            // Two different shapes of "finished" live here: a tenant-filed
            // complaint that's been resolved — whether or not the tenant has
            // confirmed it yet — and a task the landlord logged directly,
            // with no complaint behind it at all, which has no confirmation
            // step to wait on and belongs here as soon as it's done. Both
            // already dropped out of the active Requests tab (unresolved()
            // excludes 'resolved' and 'closed' alike), so showing them here
            // — status badge making the confirmed/unconfirmed distinction
            // obvious — is what keeps them from disappearing entirely.
            $data['historyComplaints'] = Complaint::accessibleBy($user)
                ->whereIn('status', ['resolved', 'closed'])
                ->with('tenant', 'house.block.property', 'property', 'maintenanceTask', 'relatedComplaint')
                ->withCount('updates')
                ->latest('resolved_at')
                ->paginate($this->perPage, ['*'], 'complaintsPage');

            $data['historyTasks'] = (clone $tasksQuery)
                ->whereNull('complaint_id')
                ->whereIn('status', ['completed', 'closed'])
                ->latest('completed_at')
                ->paginate($this->perPage, ['*'], 'tasksPage');
        }

        if ($this->showComplaintThread && $this->activeComplaintId) {
            $data['activeComplaint'] = Complaint::with(['updates.user', 'tenant', 'house.block.property'])->find($this->activeComplaintId);
            $data['complaintUpdates'] = $data['activeComplaint']?->updates->sortBy('created_at') ?? collect();
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

            $data['tasks'] = $query->orderByRaw(self::PRIORITY_ORDER_SQL)->latest()->paginate($this->perPage);
            $data['vendors'] = Vendor::where('landlord_id', $landlordId)->orderBy('name')->get();
            $data['costService'] = app(MaintenanceCostService::class);
            $data['deductingTask'] = $this->deductingTaskId ? MaintenanceTask::with('complaint')->find($this->deductingTaskId) : null;

            // Integration Phase A: keyed via each task's linked Expense (the
            // canonical decidable, shared with Expenses\Index) — a task with
            // no expense yet has nothing to decide liability against.
            $liabilityService = app(\App\Services\TenantLiabilityDecisionService::class);
            $data['decisionsByTaskId'] = $data['tasks']->getCollection()->mapWithKeys(
                fn (MaintenanceTask $t) => [$t->id => $t->expense ? $liabilityService->activeDecisionFor($t->expense) : null]
            );
            $decidingTask = $this->decidingTaskId ? MaintenanceTask::find($this->decidingTaskId) : null;
            $data['decidingTask'] = $decidingTask;
            $data['decidingCandidate'] = $decidingTask ? app(MaintenanceCostService::class)->candidateTenancyFor($decidingTask) : null;
            $data['decidingTenancies'] = $decidingTask ? $this->everyTenancyForTask($decidingTask) : collect();
            $data['decidingSelectedTenancy'] = $this->liability_tenancy_id
                ? $data['decidingTenancies']->firstWhere('id', $this->liability_tenancy_id)
                : null;
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
