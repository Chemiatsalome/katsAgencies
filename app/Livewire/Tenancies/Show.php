<?php

namespace App\Livewire\Tenancies;

use App\Models\AppNotification;
use App\Models\Complaint;
use App\Models\DepositDeduction;
use App\Models\DueDayChangeRequest;
use App\Models\MaintenanceTask;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\TenancyChangeRequest;
use App\Models\TenancyNotice;
use App\Models\TenancyRenewalRequest;
use App\Services\ContractService;
use App\Services\DepositService;
use App\Services\NotificationService;
use App\Services\RentScheduleService;
use App\Services\TenancyChangeRequestService;
use App\Services\TenancyNoticeService;
use App\Services\TenancyRenewalService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
class Show extends Component
{
    use WithFileUploads;

    public Tenancy $tenancy;

    public bool $showMoveOutForm = false;

    public bool $showUploadForm = false;

    public $contractFile;

    public string $moveOutStatus = 'completed';

    public string $terminationReason = '';

    public string $actualMoveOutDate = '';

    public string $endTenancyNotes = '';

    public string $finalRefundAmount = '';

    public bool $acknowledgeUnpaidBalance = false;

    public bool $showRefundForm = false;

    public string $refund_amount = '';

    public string $refund_date;

    public string $refund_notes = '';

    public bool $showDeductionForm = false;

    public string $deduction_amount = '';

    public string $deduction_reason = '';

    public string $deduction_date;

    public $deduction_evidence;

    public ?int $voidingDeductionId = null;

    public string $void_reason = '';

    public bool $showTerminationSummary = false;

    public bool $showGiveNoticeForm = false;

    public string $notice_reason_category = 'other';

    public string $notice_reason_details = '';

    public string $notice_date = '';

    public string $notice_period_days = '';

    public $notice_evidence;

    public ?int $decidingNoticeId = null;

    public string $decision = 'decline';

    public string $decision_note = '';

    public string $decision_new_lease_end_date = '';

    public bool $acknowledgeRepeatNoticeHistory = false;

    public ?int $withdrawingNoticeId = null;

    public string $withdraw_reason = '';

    public ?int $decidingChangeRequestId = null;

    public string $change_decision = 'accepted';

    public string $change_decision_note = '';

    public ?int $decidingRenewalId = null;

    public string $renewal_decision = 'approve';

    public string $renewal_decision_note = '';

    public bool $showProposeAlternative = false;

    public bool $showDueDayHistory = false;

    public bool $showWaiverHistory = false;

    /** True only on the one request right after the tenant was just created — drives the "Tenant added successfully" banner. */
    public bool $justCreated = false;

    public function toggleDueDayHistory(): void
    {
        $this->showDueDayHistory = ! $this->showDueDayHistory;
    }

    public function toggleWaiverHistory(): void
    {
        $this->showWaiverHistory = ! $this->showWaiverHistory;
    }

    public string $alt_duration_months = '12';

    public string $alt_new_end_date = '';

    public string $alt_note = '';

    public function mount(Tenancy $tenancy): void
    {
        $this->authorize('view', $tenancy);
        $this->tenancy = $tenancy;
        $this->justCreated = request()->boolean('created');
        $this->actualMoveOutDate = now()->format('Y-m-d');
        $this->refund_date = now()->format('Y-m-d');
        $this->deduction_date = now()->format('Y-m-d');

        // A brand-new tenancy has no RentCharge row at all until the nightly
        // rent:generate-charges cron (or a dashboard visit) runs — without
        // this, a landlord who invites a tenant and immediately opens their
        // page sees "nothing to record against" even though the tenant may
        // have already paid. ensureChargesForPeriod() is idempotent and
        // already called this same way from every other page that needs the
        // current period's charges to exist (Landlord\Dashboard, Finance\
        // Overview, RentCharges\Index, ...).
        app(RentScheduleService::class)->ensureChargesForPeriod(
            null,
            $tenancy->house->block->property_id,
            (int) now()->year,
            (int) now()->month,
        );
    }

    #[On('payment-recorded')]
    #[On('deposit-recorded')]
    public function refreshTenancy(): void
    {
        // Triggers a re-render; the render() method reloads all fresh data.
    }

    /**
     * Validates everything End Tenancy needs, same as the single-step
     * moveOut() used to, but writes nothing yet — it only reveals the
     * confirmation summary. The actual write happens in confirmTermination().
     */
    public function reviewTermination(): void
    {
        $this->authorize('update', $this->tenancy);

        if (! in_array($this->tenancy->fresh()->status, Tenancy::OCCUPYING_STATUSES, true)) {
            session()->flash('error', 'This tenancy has already been ended.');
            $this->showMoveOutForm = false;

            return;
        }

        $this->validate([
            'moveOutStatus' => ['required', 'in:completed,terminated'],
            'terminationReason' => [$this->moveOutStatus === 'terminated' ? 'required' : 'nullable', 'string', 'max:255'],
            'actualMoveOutDate' => ['required', 'date'],
            'endTenancyNotes' => ['nullable', 'string'],
            'finalRefundAmount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $outstandingBalance = $this->tenancy->balance();

        if ($outstandingBalance > 0 && ! $this->acknowledgeUnpaidBalance) {
            $this->addError('acknowledgeUnpaidBalance', "This tenant still owes KSh ".number_format($outstandingBalance).". Check the box below to confirm you want to end the tenancy anyway.");

            return;
        }

        $this->showTerminationSummary = true;
    }

    public function cancelTerminationSummary(): void
    {
        $this->showTerminationSummary = false;
    }

    /**
     * The actual write — only reachable after reviewTermination() has
     * already validated and the landlord has seen the confirmation summary.
     * Re-validates defensively rather than trusting the component's prior
     * state, since showTerminationSummary alone is forgeable in the
     * Livewire payload the same way acknowledgeUnpaidBalance was.
     */
    public function confirmTermination(): void
    {
        $this->authorize('update', $this->tenancy);

        if (! in_array($this->tenancy->fresh()->status, Tenancy::OCCUPYING_STATUSES, true)) {
            session()->flash('error', 'This tenancy has already been ended.');
            $this->showMoveOutForm = false;
            $this->showTerminationSummary = false;

            return;
        }

        $this->validate([
            'moveOutStatus' => ['required', 'in:completed,terminated'],
            'terminationReason' => [$this->moveOutStatus === 'terminated' ? 'required' : 'nullable', 'string', 'max:255'],
            'actualMoveOutDate' => ['required', 'date'],
            'endTenancyNotes' => ['nullable', 'string'],
            'finalRefundAmount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $outstandingBalance = $this->tenancy->balance();

        if ($outstandingBalance > 0 && ! $this->acknowledgeUnpaidBalance) {
            $this->addError('acknowledgeUnpaidBalance', "This tenant still owes KSh ".number_format($outstandingBalance).". Check the box below to confirm you want to end the tenancy anyway.");
            $this->showTerminationSummary = false;

            return;
        }

        if ($outstandingBalance > 0) {
            activity('tenancy_overrides')
                ->causedBy(Auth::user())
                ->performedOn($this->tenancy)
                ->withProperties([
                    'tenant' => $this->tenancy->tenant->name,
                    'outstanding_balance' => (string) $outstandingBalance,
                    'move_out_status' => $this->moveOutStatus,
                ])
                ->log('Tenancy ended with an outstanding rent balance');
        }

        $this->tenancy->update([
            'status' => $this->moveOutStatus,
            'move_out_date' => $this->actualMoveOutDate,
            'termination_reason' => $this->moveOutStatus === 'terminated' ? $this->terminationReason : null,
            'notes' => $this->endTenancyNotes !== '' ? $this->endTenancyNotes : $this->tenancy->notes,
        ]);

        app(TenancyNoticeService::class)->closeForTermination($this->tenancy, Auth::id());

        if ($this->finalRefundAmount !== '' && (float) $this->finalRefundAmount > 0) {
            try {
                app(DepositService::class)->recordRefund(
                    $this->tenancy,
                    (float) $this->finalRefundAmount,
                    $this->actualMoveOutDate,
                    Auth::id(),
                );
            } catch (InvalidArgumentException $e) {
                // The tenancy itself still ends either way — a bad refund
                // amount here shouldn't block ending it. Deductions/refunds
                // can still be recorded afterward from the Deposit section.
                session()->flash('error', "Tenancy ended, but the final refund wasn't recorded: {$e->getMessage()}");
            }
        }

        app(NotificationService::class)->notify(
            $this->tenancy->tenant,
            'tenancy_ended',
            'Your Tenancy Has Ended',
            'Your tenancy at '.($this->tenancy->house->house_number ?? 'your unit').' was formally ended on '.$this->tenancy->move_out_date->format('d M Y').'.',
            ['in_app', 'email'],
            $this->tenancy,
        );

        $this->showMoveOutForm = false;
        $this->showTerminationSummary = false;
        session()->flash('status', 'Tenancy ended.');
    }

    /** Everything the landlord should see before formally ending a tenancy — never computed unless the summary is actually about to be shown. */
    private function buildTerminationSummary(): array
    {
        $tenancy = $this->tenancy;

        return [
            'tenant' => $tenancy->tenant,
            'house' => $tenancy->house,
            'contractHistory' => $tenancy->contracts()->orderByDesc('version')->get(),
            'noticeHistory' => $tenancy->notices()->with(['issuedBy', 'decidedBy', 'events'])->orderByDesc('sequence_number')->get(),
            'agreedFinalDate' => $tenancy->activeNotice()?->final_vacate_date ?? $tenancy->activeNotice()?->proposed_vacate_date,
            'rentBalance' => $tenancy->balance(),
            'depositOutstanding' => app(DepositService::class)->outstanding($tenancy),
            'depositHeld' => app(DepositService::class)->heldBalance($tenancy),
            'depositDeducted' => app(DepositService::class)->deductedTotal($tenancy),
            'depositRefundedTotal' => app(DepositService::class)->refundedTotal($tenancy),
            // §8 (design approved 2026-10-25): split into what's reliably
            // known to belong to THIS tenancy (tenancy_id captured at
            // creation — see Complaint/MaintenanceTask::tenancy_id) versus
            // everything else on the same unit/tenant that simply hasn't
            // been resolved yet. The "other" figures are never claimed as
            // this tenancy's — house_id/tenant_id alone never uniquely
            // identify an occupancy period, so counting them against it
            // would misattribute a previous tenant's leftover item to
            // whoever currently occupies the unit.
            'openMaintenanceOnThisTenancyCount' => MaintenanceTask::where('tenancy_id', $tenancy->id)->open()->count(),
            'openMaintenanceOtherOnUnitCount' => MaintenanceTask::where('house_id', $tenancy->house_id)->open()
                ->where(fn ($q) => $q->whereNull('tenancy_id')->orWhere('tenancy_id', '!=', $tenancy->id))
                ->count(),
            'unresolvedComplaintsOnThisTenancyCount' => Complaint::where('tenancy_id', $tenancy->id)->unresolved()->count(),
            'unresolvedComplaintsOtherByTenantCount' => Complaint::where('tenant_id', $tenancy->tenant_id)->unresolved()
                ->where(fn ($q) => $q->whereNull('tenancy_id')->orWhere('tenancy_id', '!=', $tenancy->id))
                ->count(),
            'unresolvedDisputedDeductionCount' => $tenancy->depositDeductions()->whereNotNull('disputed_at')->whereNull('dispute_resolved_at')->count(),
        ];
    }

    public function toggleGiveNoticeForm(): void
    {
        $this->authorize('update', $this->tenancy);

        $this->showGiveNoticeForm = ! $this->showGiveNoticeForm;
        $this->notice_reason_category = 'other';
        $this->notice_reason_details = '';
        $this->notice_date = now()->format('Y-m-d');
        $this->notice_period_days = (string) ($this->tenancy->notice_period_days ?? '');
        $this->notice_evidence = null;
    }

    public function issueNotice(): void
    {
        $this->authorize('update', $this->tenancy);

        $data = $this->validate([
            'notice_reason_category' => ['required', 'in:'.implode(',', TenancyNotice::REASON_CATEGORIES)],
            'notice_reason_details' => ['nullable', 'string', 'max:2000'],
            'notice_date' => ['required', 'date'],
            'notice_period_days' => ['nullable', 'integer', 'min:0'],
            'notice_evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        try {
            app(TenancyNoticeService::class)->issue(
                $this->tenancy,
                Auth::id(),
                $data['notice_reason_category'],
                $data['notice_reason_details'] ?: null,
                $data['notice_date'],
                $data['notice_period_days'] !== '' && $data['notice_period_days'] !== null ? (int) $data['notice_period_days'] : null,
                $this->notice_evidence ?: null,
            );
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->showGiveNoticeForm = false;
        session()->flash('status', 'Notice issued and the tenant has been notified.');
    }

    public function startDecideNotice(int $noticeId): void
    {
        $notice = $this->tenancy->notices()->whereKey($noticeId)->firstOrFail();

        $this->decidingNoticeId = $noticeId;
        $this->decision = match ($notice->tenant_response_type) {
            'request_time' => 'approve_time',
            'request_extension' => 'approve_extension',
            default => 'decline',
        };
        $this->decision_note = '';
        $this->decision_new_lease_end_date = $this->tenancy->lease_end_date?->addYear()->format('Y-m-d') ?? now()->addYear()->format('Y-m-d');
        $this->acknowledgeRepeatNoticeHistory = false;
    }

    public function cancelDecideNotice(): void
    {
        $this->reset(['decidingNoticeId', 'decision', 'decision_note', 'decision_new_lease_end_date', 'acknowledgeRepeatNoticeHistory']);
        $this->decision = 'decline';
    }

    public function decideNotice(): void
    {
        $this->authorize('update', $this->tenancy);

        $notice = $this->tenancy->notices()->whereKey($this->decidingNoticeId)->firstOrFail();

        $this->validate([
            'decision' => ['required', 'in:'.implode(',', TenancyNotice::DECISIONS)],
            'decision_note' => ['nullable', 'string', 'max:2000'],
            'decision_new_lease_end_date' => [$this->decision === 'approve_extension' ? 'required' : 'nullable', 'date', 'after:today'],
        ]);

        // Re-checked server-side, same reasoning as acknowledgeUnpaidBalance
        // in termination — a deliberate "did you look at the history" gate
        // for a repeat notice, not something a forged payload can skip.
        if ($notice->requiresReviewBanner() && ! $this->acknowledgeRepeatNoticeHistory) {
            $this->addError('acknowledgeRepeatNoticeHistory', "This is the tenant's {$notice->sequence_number}th notice — review their full history below before deciding.");

            return;
        }

        try {
            app(TenancyNoticeService::class)->decide(
                $notice,
                Auth::id(),
                $this->decision,
                $this->decision_note ?: null,
                $this->decision === 'approve_extension' ? $this->decision_new_lease_end_date : null,
            );
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelDecideNotice();
        session()->flash('status', 'Decision recorded and the tenant has been notified.');
    }

    public function startWithdrawNotice(int $noticeId): void
    {
        $this->withdrawingNoticeId = $noticeId;
        $this->withdraw_reason = '';
    }

    public function cancelWithdrawNotice(): void
    {
        $this->reset(['withdrawingNoticeId', 'withdraw_reason']);
    }

    public function withdrawNotice(): void
    {
        $this->authorize('update', $this->tenancy);

        $notice = $this->tenancy->notices()->whereKey($this->withdrawingNoticeId)->firstOrFail();

        $this->validate([
            'withdraw_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            app(TenancyNoticeService::class)->withdraw($notice, Auth::id(), $this->withdraw_reason ?: null);
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelWithdrawNotice();
        session()->flash('status', 'Notice withdrawn.');
    }

    public function startDecideChangeRequest(int $requestId): void
    {
        $this->decidingChangeRequestId = $requestId;
        $this->change_decision = 'accepted';
        $this->change_decision_note = '';
    }

    public function cancelDecideChangeRequest(): void
    {
        $this->reset(['decidingChangeRequestId', 'change_decision', 'change_decision_note']);
        $this->change_decision = 'accepted';
    }

    public function decideChangeRequest(): void
    {
        $this->authorize('update', $this->tenancy);

        $request = $this->tenancy->changeRequests()->whereKey($this->decidingChangeRequestId)->firstOrFail();

        $this->validate([
            'change_decision' => ['required', 'in:accepted,rejected'],
            'change_decision_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(TenancyChangeRequestService::class)->decide($request, Auth::id(), $this->change_decision, $this->change_decision_note ?: null);
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelDecideChangeRequest();
        session()->flash('status', 'Decision recorded and the tenant has been notified.');
    }

    public function startDecideRenewal(int $requestId): void
    {
        $request = $this->tenancy->renewalRequests()->whereKey($requestId)->firstOrFail();

        $this->decidingRenewalId = $requestId;
        $this->renewal_decision = 'approve';
        $this->renewal_decision_note = '';
        $this->showProposeAlternative = false;
        $this->alt_duration_months = (string) $request->requested_duration_months;
        $this->alt_new_end_date = $request->preferred_new_end_date?->format('Y-m-d') ?? '';
        $this->alt_note = '';
    }

    public function cancelDecideRenewal(): void
    {
        $this->reset(['decidingRenewalId', 'renewal_decision', 'renewal_decision_note', 'showProposeAlternative', 'alt_duration_months', 'alt_new_end_date', 'alt_note']);
        $this->renewal_decision = 'approve';
        $this->alt_duration_months = '12';
    }

    public function toggleProposeAlternative(): void
    {
        $this->showProposeAlternative = ! $this->showProposeAlternative;
    }

    public function decideRenewal(): void
    {
        $this->authorize('update', $this->tenancy);

        $request = $this->tenancy->renewalRequests()->whereKey($this->decidingRenewalId)->firstOrFail();

        $this->validate([
            'renewal_decision' => ['required', 'in:approve,decline'],
            'renewal_decision_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(TenancyRenewalService::class)->decide($request, Auth::id(), $this->renewal_decision, $this->renewal_decision_note ?: null);
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelDecideRenewal();
        session()->flash('status', 'Decision recorded and the tenant has been notified.');
    }

    public function proposeAlternativeRenewalTerms(): void
    {
        $this->authorize('update', $this->tenancy);

        $request = $this->tenancy->renewalRequests()->whereKey($this->decidingRenewalId)->firstOrFail();

        $data = $this->validate([
            'alt_duration_months' => ['required', 'integer', 'min:1', 'max:60'],
            'alt_new_end_date' => ['nullable', 'date', 'after:today'],
            'alt_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(TenancyRenewalService::class)->proposeAlternative(
                $request, Auth::id(), (int) $data['alt_duration_months'], $data['alt_new_end_date'] ?: null, $data['alt_note'] ?: null,
            );
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelDecideRenewal();
        session()->flash('status', 'Alternative terms proposed — the tenant has been notified.');
    }

    /**
     * Gated on tenancies.manage_contracts, not TenancyPolicy::update()'s
     * tenancies.terminate — the two used to be the same checkbox (no
     * dedicated contracts permission existed), which meant termination
     * rights silently also granted contract management. authorize('update',
     * $this->tenancy) already re-checked property scoping; this keeps that.
     */
    private function authorizeContractManagement(): void
    {
        $this->authorize('view', $this->tenancy);
        abort_unless(Auth::user()->hasRole('landlord') || Auth::user()->hasManagerPermission('tenancies.manage_contracts'), 403);
    }

    public function generateContract(): void
    {
        $this->authorizeContractManagement();

        try {
            app(ContractService::class)->generateForTenancy($this->tenancy);
            session()->flash('status', 'Contract generated.');
        } catch (\RuntimeException $e) {
            session()->flash('error', 'No active default contract template is configured. Set one up under Contract Templates first.');
        }
    }

    public function toggleUploadForm(): void
    {
        $this->authorizeContractManagement();
        $this->showUploadForm = ! $this->showUploadForm;
    }

    public function uploadContract(): void
    {
        $this->authorizeContractManagement();

        $this->validate([
            'contractFile' => ['required', 'file', 'max:10240', 'mimes:pdf'],
        ]);

        app(ContractService::class)->uploadForTenancy($this->tenancy, $this->contractFile, Auth::id());

        $this->reset(['contractFile', 'showUploadForm']);
        session()->flash('status', 'Uploaded contract saved as a new version.');
    }

    public function sendContract(): void
    {
        $this->authorizeContractManagement();

        $contract = $this->tenancy->contract;
        abort_unless($contract, 404);

        app(ContractService::class)->markSent($contract);

        app(NotificationService::class)->notify(
            $this->tenancy->tenant,
            'contract_sent',
            'Your Tenancy Agreement Is Ready to Sign',
            'Your landlord has sent your tenancy agreement. Please review and sign it from your tenant portal.',
            ['in_app', 'email'],
            $this->tenancy,
        );

        session()->flash('status', 'Contract sent to tenant.');
    }

    public function downloadContract()
    {
        $contract = $this->tenancy->contract;
        abort_unless($contract && $contract->document, 404);
        $this->authorize('view', $contract);

        return response()->download(
            Storage::disk('local')->path($contract->document->path),
            $contract->document->original_filename
        );
    }

    /**
     * A single printable record of everything on this tenancy — for a
     * landlord to hand to a court or mediator in a dispute. Written to a
     * temp file and downloaded from disk (like downloadContract() above)
     * rather than returning the in-memory PDF bytes directly — a Livewire
     * action returning raw binary content gets folded into the same JSON
     * response Livewire uses for its own component-update protocol, and
     * that JSON encoding throws on the binary bytes. A real file path
     * downloads through Livewire's dedicated file-download effect instead.
     */
    public function downloadTenancyReport()
    {
        $this->authorize('view', $this->tenancy);

        $service = app(\App\Services\TenancyReportService::class);
        $filename = $service->filenameFor($this->tenancy);
        $path = Storage::disk('local')->path('tmp/'.\Illuminate\Support\Str::random(20).'.pdf');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $service->generate($this->tenancy)->save($path);

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    public function requestOutstandingDeposit(): void
    {
        $this->authorize('update', $this->tenancy);

        try {
            app(DepositService::class)->requestOutstanding($this->tenancy);
            session()->flash('status', 'Deposit payment request sent to the tenant.');
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelDeductionForm(): void
    {
        $this->reset(['deduction_amount', 'deduction_reason', 'deduction_evidence', 'showDeductionForm']);
        $this->deduction_date = now()->format('Y-m-d');
    }

    public function recordDeduction(): void
    {
        $this->authorize('update', $this->tenancy);

        $this->validate([
            'deduction_amount' => ['required', 'numeric', 'min:0.01'],
            'deduction_reason' => ['required', 'string', 'max:1000'],
            'deduction_date' => ['required', 'date'],
            'deduction_evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        try {
            app(DepositService::class)->recordDeduction(
                $this->tenancy,
                (float) $this->deduction_amount,
                $this->deduction_reason,
                $this->deduction_date,
                Auth::id(),
                $this->deduction_evidence ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('deduction_amount', $e->getMessage());

            return;
        }

        $this->cancelDeductionForm();
        session()->flash('status', 'Deduction recorded.');
    }

    public function startVoidDeduction(int $deductionId): void
    {
        $this->voidingDeductionId = $deductionId;
        $this->void_reason = '';
    }

    public function cancelVoidDeduction(): void
    {
        $this->reset(['voidingDeductionId', 'void_reason']);
    }

    public function voidDeduction(): void
    {
        $this->authorize('update', $this->tenancy);

        $this->validate([
            'void_reason' => ['required', 'string', 'max:1000'],
        ]);

        $deduction = DepositDeduction::where('tenancy_id', $this->tenancy->id)->findOrFail($this->voidingDeductionId);

        try {
            app(DepositService::class)->voidDeduction($deduction, $this->void_reason, Auth::id());
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cancelVoidDeduction();
        session()->flash('status', 'Deduction voided.');
    }

    public function resolveDispute(int $deductionId, string $resolution): void
    {
        $this->authorize('update', $this->tenancy);

        $deduction = DepositDeduction::where('tenancy_id', $this->tenancy->id)->findOrFail($deductionId);

        try {
            app(DepositService::class)->resolveDispute($deduction, $resolution, Auth::id());
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', $resolution === 'void' ? 'Deduction voided.' : 'Deduction upheld — dispute resolved.');
    }

    /**
     * A tenant's "I paid offline" claim, verified and accepted — the
     * moment status flips to 'confirmed', PaymentObserver re-runs
     * RentCharge::recalculateStatus(), which is what actually moves this
     * money into the ledger. Never redundant for an M-Pesa/manual-entry
     * payment: those are already 'confirmed' from the moment they're
     * created and never reach this button at all (see the blade gate).
     */
    public function confirmPayment(int $paymentId): void
    {
        $this->authorize('update', $this->tenancy);

        $payment = Payment::where('tenancy_id', $this->tenancy->id)->findOrFail($paymentId);

        if ($payment->status === 'confirmed') {
            return;
        }

        $payment->forceFill([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => Auth::id(),
        ])->save();

        app(NotificationService::class)->markRelatedRead($payment, Auth::user());

        app(NotificationService::class)->notify(
            $this->tenancy->tenant,
            'payment_confirmed_by_landlord',
            'Your Payment Was Confirmed',
            'Your landlord confirmed your KSh '.number_format((float) $payment->amount, 2).' payment — it now reflects in your balance.',
            ['in_app', 'email'],
            $payment,
        );

        session()->flash('status', 'Payment confirmed.');
    }

    /**
     * The landlord doesn't accept this claim as-is — reachable from either
     * 'awaiting_landlord_confirmation' (never happened, wrong amount) or
     * an already-'confirmed' payment that turns out to be wrong (a
     * correction, not just a first review). Either way the status moving
     * away from 'confirmed' is what pulls it back out of the ledger —
     * PaymentObserver re-runs recalculateStatus() the instant this saves.
     */
    public function disputePayment(int $paymentId, string $reason): void
    {
        $this->authorize('update', $this->tenancy);

        $reason = trim($reason);

        if ($reason === '') {
            session()->flash('error', 'A reason is required to dispute a payment.');

            return;
        }

        $payment = Payment::where('tenancy_id', $this->tenancy->id)->findOrFail($paymentId);

        $payment->forceFill([
            'status' => 'disputed',
            'disputed_at' => now(),
            'dispute_reason' => $reason,
            'disputed_by' => Auth::id(),
        ])->save();

        app(NotificationService::class)->markRelatedRead($payment, Auth::user());

        app(NotificationService::class)->notify(
            $this->tenancy->tenant,
            'payment_disputed',
            'Your Payment Was Disputed',
            'Your landlord disputed your KSh '.number_format((float) $payment->amount, 2)." payment claim: \"{$reason}\". Please check the details and submit a corrected payment if needed.",
            ['in_app', 'email'],
            $payment,
        );

        session()->flash('status', 'Payment disputed — the tenant has been notified.');
    }

    public function cancelRefundForm(): void
    {
        $this->reset(['refund_amount', 'refund_notes', 'showRefundForm']);
        $this->refund_date = now()->format('Y-m-d');
    }

    public function recordRefund(): void
    {
        $this->authorize('update', $this->tenancy);

        $this->validate([
            'refund_amount' => ['required', 'numeric', 'min:0.01'],
            'refund_date' => ['required', 'date'],
            'refund_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            app(DepositService::class)->recordRefund(
                $this->tenancy,
                (float) $this->refund_amount,
                $this->refund_date,
                Auth::id(),
                $this->refund_notes ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('refund_amount', $e->getMessage());

            return;
        }

        $this->cancelRefundForm();
        session()->flash('status', 'Refund recorded.');
    }

    /**
     * Case-by-case forgiveness of an already-applied late fee — e.g. the
     * tenant had a genuine reason for paying late. Reverses exactly the fee
     * amount out of amount_expected (not a blanket re-zero, so any other
     * adjustment to the charge survives) and leaves late_fee_charged_at set,
     * so the scheduled/opportunistic late-fee job never re-applies a fresh
     * fee to this same charge later.
     */
    public function waiveLateFee(int $chargeId): void
    {
        $this->authorize('update', $this->tenancy);

        $charge = $this->tenancy->rentCharges()->whereKey($chargeId)->firstOrFail();
        $waived = $charge->waiveLateFee(Auth::user()->name);

        if ($waived > 0) {
            session()->flash('status', 'Late fee waived.');
        }
    }

    public function acceptDueDayChangeRequest(int $requestId): void
    {
        $this->authorize('update', $this->tenancy);

        $request = $this->tenancy->dueDayChangeRequests()->pending()->whereKey($requestId)->firstOrFail();

        $this->tenancy->update([
            'rent_due_day' => $request->requested_due_day,
            'due_day_requested_by_tenant' => true,
        ]);

        $request->update([
            'status' => 'accepted',
            'decided_by' => Auth::id(),
            'decided_at' => now(),
        ]);

        $this->markRequestNotificationRead($request);

        app(NotificationService::class)->notify(
            $this->tenancy->tenant,
            'due_day_change_decided',
            'Due Date Change Approved',
            'Your rent due date has been changed to the '.\App\Models\Tenancy::ordinalDay($request->requested_due_day).' of each month. Note: this date has no grace period — a late fee applies immediately if unpaid the day after.',
            ['in_app'],
            $this->tenancy,
        );

        session()->flash('status', 'Due date change approved.');
    }

    public function rejectDueDayChangeRequest(int $requestId, ?string $reason = null): void
    {
        $this->authorize('update', $this->tenancy);

        $request = $this->tenancy->dueDayChangeRequests()->pending()->whereKey($requestId)->firstOrFail();

        $reason = $reason !== null ? trim($reason) : null;

        $request->update([
            'status' => 'rejected',
            'decided_by' => Auth::id(),
            'decided_at' => now(),
            'decision_note' => $reason ?: null,
        ]);

        $this->markRequestNotificationRead($request);

        app(NotificationService::class)->notify(
            $this->tenancy->tenant,
            'due_day_change_decided',
            'Due Date Change Declined',
            'Your request to change your rent due date was declined. Your due date remains the '.\App\Models\Tenancy::ordinalDay($this->tenancy->dueDay()).' of each month.'.($reason ? " Reason: {$reason}" : ''),
            ['in_app'],
            $this->tenancy,
        );

        session()->flash('status', 'Due date change declined.');
    }

    /**
     * Mirrors Arrears\Index::markRequestNotificationRead — the request a
     * tenant submitted (Tenant\Contract::requestDueDayChange) is the
     * notification's related model, so resolving it here can clear exactly
     * that alert without touching any other pending request's badge.
     */
    private function markRequestNotificationRead(DueDayChangeRequest $request): void
    {
        AppNotification::where('related_type', DueDayChangeRequest::class)
            ->where('related_id', $request->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function render()
    {
        $this->tenancy->refresh();

        return view('livewire.tenancies.show', [
            'contract' => $this->tenancy->contract,
            'contractHistory' => $this->tenancy->contracts()->orderByDesc('version')->get(),
            'rentCharges' => $this->tenancy->rentCharges()->orderByDesc('period_year')->orderByDesc('period_month')->get(),
            // Gross balance (amount_expected - amount_paid) never changes —
            // this is purely a read-only, per-charge view of how much of
            // the tenancy's own advance credit (if any) already covers it,
            // oldest charge first. Never implies the charge's own status or
            // amount_paid changed; a charge fully covered by credit still
            // shows its real status (e.g. 'overdue') until a landlord acts.
            'advanceCreditAvailable' => $this->tenancy->advanceCreditAvailable(),
            'creditAppliedByChargeId' => $this->tenancy->outstandingChargesWithCredit()
                ->keyBy(fn (array $row) => $row['charge']->id)
                ->map(fn (array $row) => $row['credit_applied']),
            'payments' => $this->tenancy->payments()->latest('payment_date')->latest('id')->get(),
            'depositPayments' => $this->tenancy->depositPayments()->latest('payment_date')->get(),
            'depositRefunds' => $this->tenancy->depositRefunds()->latest('refund_date')->get(),
            'depositDeductions' => $this->tenancy->depositDeductions()->with('documents')->latest('deduction_date')->get(),
            'depositOutstanding' => app(DepositService::class)->outstanding($this->tenancy),
            'depositHeld' => app(DepositService::class)->heldBalance($this->tenancy),
            'depositDeducted' => app(DepositService::class)->deductedTotal($this->tenancy),
            'depositRefundedTotal' => app(DepositService::class)->refundedTotal($this->tenancy),
            'activeNotice' => $this->tenancy->activeNotice(),
            'noticeHistory' => $this->tenancy->notices()->with(['issuedBy', 'decidedBy', 'events.tenant', 'events.causedBy'])->orderByDesc('sequence_number')->get(),
            'noticeCount' => $this->tenancy->noticeCount(),
            'decidingNotice' => $this->decidingNoticeId ? $this->tenancy->notices()->find($this->decidingNoticeId) : null,
            'terminationSummary' => $this->showTerminationSummary ? $this->buildTerminationSummary() : null,
            'changeRequestHistory' => $this->tenancy->changeRequests()->with('decidedBy', 'documents')->latest()->get(),
            'decidingChangeRequest' => $this->decidingChangeRequestId ? $this->tenancy->changeRequests()->find($this->decidingChangeRequestId) : null,
            'renewalHistory' => $this->tenancy->renewalRequests()->with('decidedBy', 'newContract')->latest()->get(),
            'activeRenewalRequest' => $this->tenancy->activeRenewalRequest(),
            'decidingRenewal' => $this->decidingRenewalId ? $this->tenancy->renewalRequests()->find($this->decidingRenewalId) : null,
            // Audit finding C7 — only the single pending/latest-decided
            // request was ever shown; older ones existed in the DB but
            // were invisible on either side.
            'dueDayChangeHistory' => $this->tenancy->dueDayChangeRequests()->whereNotNull('decided_at')->with('decidedBy')->latest('decided_at')->get(),
            'waiverRequestHistory' => \App\Models\LateFeeWaiverRequest::where('tenancy_id', $this->tenancy->id)->whereNotNull('decided_at')->with('decidedBy', 'rentCharge')->latest('decided_at')->get(),
        ]);
    }
}
