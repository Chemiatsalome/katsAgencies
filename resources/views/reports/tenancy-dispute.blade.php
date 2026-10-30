<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Tenancy Record</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #17324D; line-height: 1.5; }
        h1 { font-size: 18px; color: #17324D; margin-bottom: 2px; }
        h2 { font-size: 13px; color: #17324D; margin-top: 22px; margin-bottom: 6px; border-bottom: 1px solid #CBD5E1; padding-bottom: 3px; }
        p.meta { color: #64748B; margin-top: 0; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table th, table td { text-align: left; padding: 3px 6px; border-bottom: 1px solid #E2E8F0; vertical-align: top; }
        table th { color: #64748B; font-weight: normal; }
        .grid { width: 100%; }
        .grid td { width: 25%; padding: 2px 6px; }
        .label { color: #64748B; }
        .muted { color: #64748B; }
        .voided { text-decoration: line-through; color: #94A3B8; }
        .section-empty { color: #94A3B8; font-style: italic; }
    </style>
</head>
<body>
    <h1>Kats Agencies — Tenancy Record</h1>
    <p class="meta">Generated {{ $generatedAt->format('d M Y, H:i') }} — compiled for dispute review. Every record below is shown as-is, including voided, declined, or withdrawn items.</p>

    <h2>Tenant &amp; Property</h2>
    <table class="grid">
        <tr>
            <td><span class="label">Tenant</span><br>{{ $tenancy->tenant->name }}</td>
            <td><span class="label">Phone</span><br>{{ $tenancy->tenant->phone ?? '—' }}</td>
            <td><span class="label">Property</span><br>{{ $tenancy->house->block->property->name }}</td>
            <td><span class="label">Unit</span><br>{{ $tenancy->house->block->name }}, {{ $tenancy->house->house_number }}</td>
        </tr>
        <tr>
            <td><span class="label">Move-in Date</span><br>{{ $tenancy->move_in_date->format('d M Y') }}</td>
            <td><span class="label">Lease End</span><br>{{ $tenancy->lease_end_date?->format('d M Y') ?? 'Open-ended' }}</td>
            <td><span class="label">Monthly Rent</span><br>KSh {{ number_format($tenancy->monthly_rent, 2) }}</td>
            <td><span class="label">Tenancy Status</span><br>{{ ucfirst(str_replace('_', ' ', $tenancy->status)) }}</td>
        </tr>
        @if ($tenancy->move_out_date)
            <tr>
                <td><span class="label">Move-out Date</span><br>{{ $tenancy->move_out_date->format('d M Y') }}</td>
                <td colspan="3"><span class="label">Termination Reason</span><br>{{ $tenancy->termination_reason ?? '—' }}</td>
            </tr>
        @endif
    </table>

    <h2>Tenancy Agreement History</h2>
    @if ($tenancy->contracts->isEmpty())
        <p class="section-empty">No agreement on file.</p>
    @else
        <table>
            <tr><th>Version</th><th>Source</th><th>Status</th><th>Signed By</th><th>Signed At</th><th>Superseded</th></tr>
            @foreach ($tenancy->contracts as $contract)
                <tr>
                    <td>v{{ $contract->version }}</td>
                    <td>{{ $contract->source === 'landlord_upload' ? 'Landlord Upload' : 'System Template' }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $contract->effectiveStatus())) }}</td>
                    <td>{{ $contract->signed_by_name ?? '—' }}</td>
                    <td>{{ $contract->signed_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td>{{ $contract->superseded_at?->format('d M Y H:i') ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Notice History</h2>
    @if ($tenancy->notices->isEmpty())
        <p class="section-empty">No notices issued.</p>
    @else
        @foreach ($tenancy->notices as $notice)
            <table>
                <tr>
                    <th style="width:20%">Notice #{{ $notice->sequence_number }}</th>
                    <td>
                        {{ ucfirst(str_replace('_', ' ', $notice->reason_category)) }} — issued {{ $notice->notice_date->format('d M Y') }} by {{ $notice->issuedBy?->name ?? '—' }}
                        @if ($notice->proposed_vacate_date) · proposed vacate date {{ $notice->proposed_vacate_date->format('d M Y') }} @endif
                        <br><strong>Status:</strong> {{ $notice->displayStatus() }}
                        @if ($notice->reason_details)<br><strong>Details:</strong> {{ $notice->reason_details }}@endif
                        @if ($notice->tenant_response_type)
                            <br><strong>Tenant response ({{ ucfirst(str_replace('_', ' ', $notice->tenant_response_type)) }}):</strong> {{ $notice->tenant_response_note ?? '—' }}
                        @endif
                        @if ($notice->decision)
                            <br><strong>Landlord decision ({{ ucfirst(str_replace('_', ' ', $notice->decision)) }}):</strong> {{ $notice->decision_note ?? '—' }}
                        @endif
                        @if ($notice->withdrawn_at)
                            <br><strong>Withdrawn</strong> {{ $notice->withdrawn_at->format('d M Y H:i') }}: {{ $notice->withdrawn_reason ?? '—' }}
                        @endif
                    </td>
                </tr>
            </table>
            @if ($notice->events->isNotEmpty())
                <table>
                    <tr><th>Event</th><th>Date</th><th>By</th><th>Note</th></tr>
                    @foreach ($notice->events as $event)
                        <tr>
                            <td>{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</td>
                            <td>{{ $event->created_at->format('d M Y H:i') }}</td>
                            <td>{{ $event->tenant?->name ?? $event->causedBy?->name ?? '—' }}</td>
                            <td>{{ $event->note ?? '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach
    @endif

    <h2>Change Requests</h2>
    @if ($tenancy->changeRequests->isEmpty())
        <p class="section-empty">No change requests.</p>
    @else
        <table>
            <tr><th>Date</th><th>Category</th><th>Requested Change</th><th>Status</th><th>Decision Note</th></tr>
            @foreach ($tenancy->changeRequests as $request)
                <tr>
                    <td>{{ $request->created_at->format('d M Y') }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $request->category)) }}</td>
                    <td>{{ $request->requested_change }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $request->status)) }}</td>
                    <td>{{ $request->decision_note ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Renewal Requests</h2>
    @if ($tenancy->renewalRequests->isEmpty())
        <p class="section-empty">No renewal requests.</p>
    @else
        <table>
            <tr><th>Date</th><th>Requested</th><th>Status</th><th>Alternative Offered</th><th>Final End Date</th></tr>
            @foreach ($tenancy->renewalRequests as $renewal)
                <tr>
                    <td>{{ $renewal->created_at->format('d M Y') }}</td>
                    <td>{{ $renewal->requested_duration_months }} month(s){{ $renewal->preferred_new_end_date ? ' ('.$renewal->preferred_new_end_date->format('d M Y').')' : '' }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $renewal->status)) }}</td>
                    <td>{{ $renewal->alternative_duration_months ? $renewal->alternative_duration_months.' month(s)' : '—' }}</td>
                    <td>{{ $renewal->final_new_end_date?->format('d M Y') ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Rent Charges</h2>
    @if ($tenancy->rentCharges->isEmpty())
        <p class="section-empty">No rent charges on record.</p>
    @else
        <table>
            <tr><th>Period</th><th>Due Date</th><th>Expected</th><th>Paid</th><th>Status</th></tr>
            @foreach ($tenancy->rentCharges as $charge)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::createFromDate($charge->period_year, $charge->period_month, 1)->format('F Y') }}</td>
                    <td>{{ $charge->due_date->format('d M Y') }}</td>
                    <td>KSh {{ number_format($charge->amount_expected, 2) }}</td>
                    <td>KSh {{ number_format($charge->amount_paid, 2) }}</td>
                    <td>{{ ucfirst($charge->status) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    <p><strong>Outstanding rent balance:</strong> KSh {{ number_format($rentBalance, 2) }}</p>

    <h2>Payment History</h2>
    @if ($tenancy->payments->isEmpty())
        <p class="section-empty">No payments on record.</p>
    @else
        <table>
            <tr><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th></tr>
            @foreach ($tenancy->payments as $payment)
                <tr>
                    <td>{{ $payment->payment_date->format('d M Y') }}</td>
                    <td>{{ ucfirst($payment->method) }}</td>
                    <td>{{ $payment->reference_number ?? '—' }}</td>
                    <td>KSh {{ number_format($payment->amount, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Security Deposit</h2>
    <table class="grid">
        <tr>
            <td><span class="label">Required</span><br>KSh {{ number_format($tenancy->deposit_required, 2) }}</td>
            <td><span class="label">Paid</span><br>KSh {{ number_format($tenancy->deposit_paid, 2) }}</td>
            <td><span class="label">Outstanding</span><br>KSh {{ number_format($depositOutstanding, 2) }}</td>
            <td><span class="label">Held Now</span><br>KSh {{ number_format($depositHeld, 2) }}</td>
        </tr>
    </table>

    <p><strong>Deposit Payments</strong></p>
    @if ($tenancy->depositPayments->isEmpty())
        <p class="section-empty">None recorded.</p>
    @else
        <table>
            <tr><th>Date</th><th>Method</th><th>Amount</th></tr>
            @foreach ($tenancy->depositPayments as $payment)
                <tr><td>{{ $payment->payment_date->format('d M Y') }}</td><td>{{ ucfirst($payment->method) }}</td><td>KSh {{ number_format($payment->amount, 2) }}</td></tr>
            @endforeach
        </table>
    @endif

    <p><strong>Deductions</strong> (including voided and disputed ones)</p>
    @if ($tenancy->depositDeductions->isEmpty())
        <p class="section-empty">None recorded.</p>
    @else
        <table>
            <tr><th>Date</th><th>Reason</th><th>Amount</th><th>Status</th></tr>
            @foreach ($tenancy->depositDeductions as $deduction)
                <tr class="{{ $deduction->isVoided() ? 'voided' : '' }}">
                    <td>{{ $deduction->deduction_date->format('d M Y') }}</td>
                    <td>
                        {{ $deduction->reason }}
                        @if ($deduction->isVoided())<br><em>Voided: {{ $deduction->void_reason }}</em>@endif
                        @if ($deduction->disputed_at)<br><em>Disputed: {{ $deduction->dispute_reason }}</em>@endif
                    </td>
                    <td>KSh {{ number_format($deduction->amount, 2) }}</td>
                    <td>{{ $deduction->isVoided() ? 'Voided' : ($deduction->disputed_at ? ($deduction->dispute_resolved_at ? 'Dispute Resolved' : 'Disputed') : 'Active') }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    <p><strong>Total active deductions:</strong> KSh {{ number_format($depositDeducted, 2) }}</p>

    <p><strong>Refunds</strong></p>
    @if ($tenancy->depositRefunds->isEmpty())
        <p class="section-empty">None recorded.</p>
    @else
        <table>
            <tr><th>Date</th><th>Notes</th><th>Amount</th></tr>
            @foreach ($tenancy->depositRefunds as $refund)
                <tr><td>{{ $refund->refund_date->format('d M Y') }}</td><td>{{ $refund->notes ?? '—' }}</td><td>KSh {{ number_format($refund->amount, 2) }}</td></tr>
            @endforeach
        </table>
    @endif
    <p><strong>Total refunded:</strong> KSh {{ number_format($depositRefundedTotal, 2) }}</p>

    <h2>Maintenance History</h2>
    @if ($maintenanceHistory->isEmpty())
        <p class="section-empty">No maintenance tasks on record for this unit.</p>
    @else
        <table>
            <tr><th>Date</th><th>Title</th><th>Category</th><th>Status</th><th>Cost</th></tr>
            @foreach ($maintenanceHistory as $task)
                <tr>
                    <td>{{ $task->created_at->format('d M Y') }}</td>
                    <td>{{ $task->title }}</td>
                    <td>{{ ucfirst($task->category) }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $task->status)) }}</td>
                    <td>{{ $task->actual_cost ? 'KSh '.number_format($task->actual_cost, 2) : '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Complaints Filed by This Tenant</h2>
    @if ($complaintHistory->isEmpty())
        <p class="section-empty">No complaints on record.</p>
    @else
        <table>
            <tr><th>Date</th><th>Subject</th><th>Category</th><th>Status</th><th>Tenant Confirmed</th></tr>
            @foreach ($complaintHistory as $complaint)
                <tr>
                    <td>{{ $complaint->created_at->format('d M Y') }}</td>
                    <td>{{ $complaint->subject }}</td>
                    <td>{{ ucfirst($complaint->category) }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $complaint->status)) }}</td>
                    <td>{{ $complaint->tenant_confirmed_at?->format('d M Y') ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
