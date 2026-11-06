<?php

namespace App\Observers;

use App\Models\DepositPayment;

class DepositPaymentObserver
{
    public function created(DepositPayment $depositPayment): void
    {
        $this->recalculate($depositPayment);
    }

    public function updated(DepositPayment $depositPayment): void
    {
        $this->recalculate($depositPayment);
    }

    public function deleted(DepositPayment $depositPayment): void
    {
        $this->recalculate($depositPayment);
    }

    private function recalculate(DepositPayment $depositPayment): void
    {
        $tenancy = $depositPayment->tenancy;
        // A tenant's own unverified "I paid offline" claim (status
        // 'awaiting_landlord_confirmation') must never move this balance
        // before a landlord actually confirms it — mirrors
        // RentCharge::recalculateStatus()'s identical confirmed-only sum.
        $total = (float) $tenancy->depositPayments()->confirmed()->sum('amount');
        $tenancy->forceFill(['deposit_paid' => $total])->save();
    }
}
