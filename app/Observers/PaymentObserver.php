<?php

namespace App\Observers;

use App\Models\Payment;
use App\Models\RentCharge;

class PaymentObserver
{
    public function created(Payment $payment): void
    {
        $this->recalculate($payment);
    }

    public function updated(Payment $payment): void
    {
        $this->recalculate($payment);

        $originalRentChargeId = $payment->getOriginal('rent_charge_id');
        if ($originalRentChargeId && $originalRentChargeId !== $payment->rent_charge_id) {
            RentCharge::find($originalRentChargeId)?->recalculateStatus();
        }
    }

    public function deleted(Payment $payment): void
    {
        $this->recalculate($payment);
    }

    private function recalculate(Payment $payment): void
    {
        $payment->rentCharge?->recalculateStatus();
    }
}
