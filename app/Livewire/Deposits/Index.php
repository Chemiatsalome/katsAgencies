<?php

namespace App\Livewire\Deposits;

use App\Models\Tenancy;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    public function render()
    {
        $landlordId = Auth::id();

        $rows = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->with(['tenant', 'house.block.property', 'depositPayments', 'depositRefunds'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Tenancy $tenancy) {
                $paid = (float) $tenancy->depositPayments->sum('amount');
                $refunded = $tenancy->depositRefundedTotal();
                $deducted = $tenancy->depositDeductedTotal();

                return (object) [
                    'tenancy' => $tenancy,
                    'required' => (float) $tenancy->deposit_required,
                    'paid' => $paid,
                    'outstanding' => max(0, (float) $tenancy->deposit_required - $paid),
                    'refunded' => $refunded,
                    'deducted' => $deducted,
                    'heldBalance' => $paid - $refunded - $deducted,
                ];
            });

        return view('livewire.deposits.index', [
            'rows' => $rows,
            'totalHeld' => $rows->sum('heldBalance'),
            'totalOutstanding' => $rows->sum('outstanding'),
        ]);
    }
}
