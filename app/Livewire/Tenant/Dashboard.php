<?php

namespace App\Livewire\Tenant;

use App\Models\RentCharge;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant')]
class Dashboard extends Component
{
    public function render()
    {
        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->currentTenancy();
        $tenancy?->load('house.block.property');

        // A tenant whose tenancy has actually ended (moved out normally, or
        // had it terminated) isn't "missing a tenancy" the way someone who
        // was never assigned one is — they still need to see where it was
        // and track their deposit refund, same as My Deposit already lets
        // them do via mostRecentTenancy(). Only resolved when there's no
        // current tenancy at all, so it never shadows the active case.
        $pastTenancy = null;
        if (! $tenancy) {
            $pastTenancy = $tenant?->mostRecentTenancy();
            $pastTenancy?->load('house.block.property');
        }

        $nextCharge = $tenancy
            ? RentCharge::where('tenancy_id', $tenancy->id)->outstanding()->orderBy('due_date')->first()
            : null;

        return view('livewire.tenant.dashboard', [
            'tenant' => $tenant,
            'tenancy' => $tenancy,
            'pastTenancy' => $pastTenancy,
            'balance' => $tenancy?->balance() ?? 0,
            'depositBalance' => $tenancy?->depositBalance() ?? 0,
            'nextCharge' => $nextCharge,
            'nextRentDueDate' => $tenancy?->nextRentDueDate(),
            'recentPayments' => $tenancy
                ? $tenancy->payments()->latest('payment_date')->latest('id')->limit(5)->get()
                : collect(),
            // The page's own banner promises "maintenance requests... all in
            // one place" — previously true of nothing on this page at all.
            'recentComplaints' => $tenant
                ? $tenant->complaints()->latest()->limit(3)->get()
                : collect(),
            'openComplaintsCount' => $tenant
                ? $tenant->complaints()->where('status', '!=', 'closed')->count()
                : 0,
        ]);
    }
}
