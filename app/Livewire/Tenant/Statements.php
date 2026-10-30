<?php

namespace App\Livewire\Tenant;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Statements extends Component
{
    private function tenancyIds()
    {
        $tenant = Auth::user()->tenant;

        return $tenant ? $tenant->tenancies()->pluck('id') : collect();
    }

    public function downloadReceipt(int $paymentId)
    {
        $payment = Payment::with('tenancy.tenant', 'tenancy.house.block.property', 'rentCharge')
            ->whereIn('tenancy_id', $this->tenancyIds())
            ->findOrFail($paymentId);

        // A "receipt" is proof of an actual transaction — opening_balance
        // rows are the system's own "assume this historical month was
        // settled" guess, not something the tenant ever paid, so generating
        // an official-looking receipt document for one would be misleading.
        abort_if($payment->isOpeningBalance(), 404);

        $pdf = Pdf::loadView('tenant.receipt', ['payment' => $payment]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'receipt-'.$payment->id.'.pdf'
        );
    }

    public function downloadStatement()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->mostRecentTenancy();
        $tenancy?->load('house.block.property', 'rentCharges', 'payments');

        abort_if(! $tenancy, 404);

        $pdf = Pdf::loadView('tenant.statement', [
            'tenant' => $tenant,
            'tenancy' => $tenancy,
            'rentCharges' => $tenancy->rentCharges()->orderBy('period_year')->orderBy('period_month')->get(),
            'payments' => $tenancy->payments()->orderBy('payment_date')->get(),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'rent-statement-'.now()->format('Y-m-d').'.pdf'
        );
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->mostRecentTenancy();
        $tenancy?->load('house.block.property');

        return view('livewire.tenant.statements', [
            'tenancy' => $tenancy,
            'payments' => $tenancy
                ? $tenancy->payments()->latest('payment_date')->latest('id')->get()
                : collect(),
        ]);
    }
}
