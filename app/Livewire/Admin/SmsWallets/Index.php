<?php

namespace App\Livewire\Admin\SmsWallets;

use App\Models\LandlordSetting;
use App\Models\SmsWalletEntry;
use App\Models\User;
use App\Services\SmsWalletService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only admin visibility into every landlord's SMS wallet — balance,
 * lifetime top-ups/spend, and whether they've actually opted into SMS
 * reminders at all. Nothing here is actionable: a wallet only ever changes
 * via a real M-Pesa top-up (MpesaCallbackController's sms_topup branch) or
 * a real SMS actually sent (NotificationService::deliverSms()) — an admin
 * adjusting a balance by hand would break the ledger's one honest
 * guarantee, that every entry traces back to a real top-up or a real send.
 * The platform-wide rate itself lives in Admin > Settings > Payments.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

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
        ]);
    }
}
