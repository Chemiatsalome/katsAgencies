<?php

namespace App\Livewire\Admin\SmsWallets;

use App\Models\AppNotification;
use App\Models\LandlordSetting;
use App\Models\SmsWalletEntry;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SmsWalletService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Mostly read-only admin visibility into every landlord's SMS wallet —
 * balance, lifetime top-ups/spend, and whether they've actually opted into
 * SMS reminders at all. A wallet balance itself is never editable here: it
 * only ever changes via a real M-Pesa top-up (MpesaCallbackController's
 * sms_topup branch) or a real SMS actually sent/billed
 * (NotificationService::deliverSms()/settleBilling()) — an admin adjusting
 * it by hand would break the ledger's one honest guarantee, that every
 * entry traces back to a real top-up or a real send. The platform-wide
 * rate itself lives in Admin > Settings > Payments.
 *
 * G-1A: the one deliberately narrow exception is resolving a notification
 * stuck `submission_unknown` — see reconcileManually() on NotificationService
 * and this page's startResolve()/confirmResolve() below. Everything else
 * needsSmsReconciliation() surfaces (a confirmed send whose billing itself
 * failed) is shown read-only — it already has an auditable discrepancy
 * entry and nothing ambiguous left for an admin to decide.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public ?int $resolvingNotificationId = null;

    public string $resolveAction = '';

    public string $resolveNote = '';

    public function startResolve(int $notificationId, string $action): void
    {
        $this->resetErrorBag();
        $this->resolvingNotificationId = $notificationId;
        $this->resolveAction = $action;
        $this->resolveNote = '';
    }

    public function cancelResolve(): void
    {
        $this->reset(['resolvingNotificationId', 'resolveAction', 'resolveNote']);
    }

    public function confirmResolve(): void
    {
        abort_unless(Auth::user()->hasRole('admin'), 403);

        $this->validate(['resolveNote' => ['required', 'string', 'max:500']]);

        $notification = AppNotification::find($this->resolvingNotificationId);

        if (! $notification) {
            session()->flash('status', 'That notification is no longer awaiting reconciliation — refresh to see its current state.');
            $this->cancelResolve();

            return;
        }

        try {
            app(NotificationService::class)->reconcileManually($notification, Auth::user(), $this->resolveAction, $this->resolveNote);
        } catch (InvalidArgumentException $e) {
            $this->addError('resolveNote', $e->getMessage());

            return;
        }

        session()->flash('status', 'Resolution recorded.');
        $this->cancelResolve();
    }

    public function render()
    {
        $wallet = app(SmsWalletService::class);

        $landlords = User::approvedLandlords()
            ->orderBy('name')
            ->paginate(20)
            ->through(function (User $landlord) {
                $credits = (float) SmsWalletEntry::where('landlord_id', $landlord->id)->where('type', 'credit')->sum('amount');
                $debits = (float) SmsWalletEntry::where('landlord_id', $landlord->id)->where('type', 'debit')->sum('amount');
                $channels = explode(',', (string) LandlordSetting::get($landlord->id, 'reminder_channels', ''));

                return (object) [
                    'landlord' => $landlord,
                    'smsEnabled' => in_array('sms', $channels, true),
                    'balance' => round($credits - $debits, 2),
                    'totalToppedUp' => $credits,
                    'messagesSent' => SmsWalletEntry::where('landlord_id', $landlord->id)->where('type', 'debit')->count(),
                    'lastActivity' => SmsWalletEntry::where('landlord_id', $landlord->id)->latest()->first()?->created_at,
                ];
            });

        return view('livewire.admin.sms-wallets.index', [
            'landlords' => $landlords,
            'rate' => $wallet->rate(),
            'totalNeedsReconciliation' => AppNotification::needsSmsReconciliation()->count(),
            // Capped rather than paginated — this is meant to stay rare; a
            // sustained backlog past this many is itself a signal something
            // else is wrong, not a reason to build pagination for it.
            'reconciliationRows' => AppNotification::needsSmsReconciliation()
                ->with(['notifiable', 'reconciledBy'])
                ->latest()
                ->limit(50)
                ->get(),
        ]);
    }
}
