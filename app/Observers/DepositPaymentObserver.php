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
        $total = (float) $tenancy->depositPayments()->sum('amount');
        $tenancy->forceFill(['deposit_paid' => $total])->save();
    }
}
