<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rent Statement</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #17324D; }
        h1 { font-size: 16px; color: #17324D; margin-bottom: 2px; }
        h2 { font-size: 13px; color: #17324D; margin-top: 20px; }
        p.meta { color: #64748B; margin-top: 0; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #E2E8F0; padding: 5px 8px; text-align: left; }
        th { background-color: #F8FAFC; color: #64748B; }
        .right { text-align: right; }
        .summary td.label { color: #64748B; }
        .summary td.value { font-weight: bold; }
    </style>
</head>
<body>
    <h1>Kats Agencies — Rent Statement</h1>
    <p class="meta">Generated {{ now()->format('d M Y, H:i') }}</p>

    <table class="summary">
        <tr><td class="label">Tenant</td><td class="value">{{ $tenant->name }}</td></tr>
        <tr><td class="label">Property</td><td class="value">{{ $tenancy->house->block->property->name }}</td></tr>
        <tr><td class="label">Unit</td><td class="value">Block {{ $tenancy->house->block->name }} — {{ $tenancy->house->house_number }}</td></tr>
        <tr><td class="label">Monthly Rent</td><td class="value">KSh {{ number_format($tenancy->monthly_rent, 2) }}</td></tr>
        <tr><td class="label">Outstanding Balance</td><td class="value">KSh {{ number_format($tenancy->balance(), 2) }}</td></tr>
    </table>

    <h2>Rent Charges</h2>
    <table>
        <thead>
            <tr><th>Period</th><th class="right">Expected</th><th class="right">Paid</th><th class="right">Balance</th><th>Status</th></tr>
        </thead>
        <tbody>
            @forelse ($rentCharges as $charge)
                <tr>
                    <td>{{ \Carbon\Carbon::create()->month($charge->period_month)->format('F') }} {{ $charge->period_year }}</td>
                    <td class="right">KSh {{ number_format($charge->amount_expected, 2) }}</td>
                    <td class="right">KSh {{ number_format($charge->amount_paid, 2) }}</td>
                    <td class="right">KSh {{ number_format($charge->amount_expected - $charge->amount_paid, 2) }}</td>
                    <td>{{ ucfirst($charge->status) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No rent charges recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Payment History</h2>
    <table>
        <thead>
            <tr><th>Date</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td>{{ $payment->payment_date->format('d M Y') }}</td>
                    <td>{{ $payment->isOpeningBalance() ? 'Historical balance (not a transaction)' : ucfirst($payment->method) }}</td>
                    <td>{{ $payment->reference_number ?? '—' }}</td>
                    <td class="right">KSh {{ number_format($payment->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No payments recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
