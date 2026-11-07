<?php

namespace App\Livewire\Admin\Payments;

use App\Models\MpesaTransaction;
use App\Models\User;
use App\Services\MpesaInvestigationService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Transaction-level admin visibility — complements Admin\Disbursements\Index
 * (landlord-level payout tracking) with the raw incoming M-Pesa attempts
 * themselves, including ones that never became a Payment at all (failed,
 * cancelled, or stuck pending/unresolved).
 *
 * Phase 4 audit, Finding 4: extended from read-only to also surface and act
 * on a transaction that's stuck unresolved — re-querying Safaricom, or
 * recording an evidence-based resolution — through MpesaInvestigationService.
 * Every action re-asserts admin authorisation itself (defence in depth
 * beyond this page's own role:admin route middleware) and never bypasses
 * that service's locking/idempotency guarantees. A genuine ledger
 * discrepancy on an already-confirmed Payment still gets resolved the way
 * it always has (the landlord's own Tenancies\Show confirm/dispute flow).
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public string $landlordId = 'all';

    public ?int $resolvingTransactionId = null;

    public string $resolveAction = '';

    public string $resolveReason = '';

    public string $resolveReference = '';

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingLandlordId(): void
    {
        $this->resetPage();
    }

    public function startResolve(int $transactionId, string $action): void
    {
        $this->resetErrorBag();
        $this->resolvingTransactionId = $transactionId;
        $this->resolveAction = $action;
        $this->resolveReason = '';
        $this->resolveReference = '';
    }

    public function cancelResolve(): void
    {
        $this->reset(['resolvingTransactionId', 'resolveAction', 'resolveReason', 'resolveReference']);
    }

    public function requery(int $transactionId): void
    {
        abort_unless(Auth::user()->hasRole('admin'), 403);

        $transaction = MpesaTransaction::unresolved()->find($transactionId);

        if (! $transaction) {
            session()->flash('status', 'That transaction is no longer unresolved — refresh to see its current state.');

            return;
        }

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery', Auth::user());

        session()->flash('status', match ($query->interpreted_as) {
            'inconclusive' => 'Safaricom had no definitive answer — recorded in this transaction\'s query history.',
            'escalated_manual_verification' => 'Safaricom confirms this succeeded, but with no receipt/amount to credit from — escalated for manual verification.',
            default => 'Resolved from Safaricom\'s response.',
        });
    }

    public function confirmResolve(): void
    {
        abort_unless(Auth::user()->hasRole('admin'), 403);

        $this->validate([
            'resolveReason' => ['required', 'string', 'max:500'],
            'resolveReference' => $this->resolveAction === 'successful' ? ['required', 'string', 'max:100'] : ['nullable', 'string', 'max:100'],
        ]);

        $transaction = MpesaTransaction::find($this->resolvingTransactionId);

        if (! $transaction) {
            $this->cancelResolve();

            return;
        }

        $service = app(MpesaInvestigationService::class);

        try {
            match ($this->resolveAction) {
                'failed' => $service->resolveAsFailed($transaction, Auth::user(), $this->resolveReason),
                'cancelled' => $service->resolveAsCancelled($transaction, Auth::user(), $this->resolveReason),
                'successful' => $service->resolveAsSuccessful($transaction, Auth::user(), $this->resolveReference, $this->resolveReason),
                'escalate' => $service->escalateForManualVerification($transaction, Auth::user(), $this->resolveReason),
                default => throw new InvalidArgumentException('Unknown resolution action.'),
            };
        } catch (InvalidArgumentException $e) {
            $this->addError('resolveReason', $e->getMessage());

            return;
        }

        session()->flash('status', 'Transaction resolved.');
        $this->cancelResolve();
    }

    public function render()
    {
        $query = MpesaTransaction::query()
            ->with(['tenancy.tenant', 'tenancy.house.block.property.landlord', 'landlord', 'statusQueries', 'investigatedBy', 'cancellationRequestedBy'])
            ->latest('created_at');

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if ($this->landlordId !== 'all') {
            // An sms_topup row has no tenancy — it's landlord_id directly,
            // not derived through tenancy->house->block->property.
            $query->where(fn ($q) => $q
                ->whereHas('tenancy.house.block.property', fn ($q2) => $q2->where('landlord_id', $this->landlordId))
                ->orWhere('landlord_id', $this->landlordId));
        }

        if (filled($this->search)) {
            $query->where(function ($q) {
                $q->where('mpesa_receipt_number', 'like', "%{$this->search}%")
                    ->orWhere('checkout_request_id', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%");
            });
        }

        $transactions = $query->paginate(25);

        // Counts for the filter chips reflect the whole table, not just the
        // current page — an admin scanning for problems needs to see "12
        // failed" even while looking at a filtered, paginated list.
        $statusCounts = MpesaTransaction::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('livewire.admin.payments.index', [
            'transactions' => $transactions,
            'statusCounts' => $statusCounts,
            'landlords' => User::role('landlord')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
