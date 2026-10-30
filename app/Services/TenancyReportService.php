<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\MaintenanceTask;
use App\Models\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A single, printable PDF compiling a tenancy's full record — agreement
 * history, notices and their responses/decisions, change and renewal
 * requests, rent and payment history, every deposit transaction (including
 * voided and disputed ones), and maintenance history. Built for a landlord
 * to hand to a court or mediator in a dispute, so it deliberately includes
 * voided/declined/withdrawn items rather than hiding them — the point is a
 * complete record, not a flattering one. Generated on demand; never
 * persisted as a Document, since the underlying data is already the
 * permanent record.
 */
class TenancyReportService
{
    public function generate(Tenancy $tenancy)
    {
        $tenancy->loadMissing([
            'tenant', 'house.block.property.landlord',
            'contracts' => fn ($q) => $q->orderByDesc('version'),
            'rentCharges' => fn ($q) => $q->orderByDesc('period_year')->orderByDesc('period_month'),
            'payments' => fn ($q) => $q->latest('payment_date'),
            'depositPayments' => fn ($q) => $q->latest('payment_date'),
            'depositDeductions' => fn ($q) => $q->latest('deduction_date'),
            'depositRefunds' => fn ($q) => $q->latest('refund_date'),
            'notices' => fn ($q) => $q->with(['issuedBy', 'decidedBy', 'events.tenant', 'events.causedBy'])->orderByDesc('sequence_number'),
            'changeRequests' => fn ($q) => $q->with('decidedBy')->latest(),
            'renewalRequests' => fn ($q) => $q->with('decidedBy')->latest(),
        ]);

        $maintenanceHistory = MaintenanceTask::where('house_id', $tenancy->house_id)
            ->with('vendor')
            ->orderByDesc('created_at')
            ->get();

        $complaintHistory = Complaint::where('tenant_id', $tenancy->tenant_id)
            ->orderByDesc('created_at')
            ->get();

        $depositService = app(DepositService::class);

        $pdf = Pdf::loadView('reports.tenancy-dispute', [
            'tenancy' => $tenancy,
            'maintenanceHistory' => $maintenanceHistory,
            'complaintHistory' => $complaintHistory,
            'depositOutstanding' => $depositService->outstanding($tenancy),
            'depositHeld' => $depositService->heldBalance($tenancy),
            'depositDeducted' => $depositService->deductedTotal($tenancy),
            'depositRefundedTotal' => $depositService->refundedTotal($tenancy),
            'rentBalance' => $tenancy->balance(),
            'generatedAt' => now(),
        ])->setPaper('a4');

        return $pdf;
    }

    public function filenameFor(Tenancy $tenancy): string
    {
        return 'Tenancy Record - '.$tenancy->tenant->name.' - '.now()->format('Y-m-d').'.pdf';
    }
}
