<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #17324D; }
        h1 { font-size: 18px; color: #17324D; margin-bottom: 2px; }
        p.meta { color: #64748B; margin-top: 0; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        td { padding: 6px 8px; }
        td.label { color: #64748B; width: 40%; }
        td.value { font-weight: bold; }
        .amount { font-size: 20px; color: #178B4A; margin: 16px 0; }
    </style>
</head>
<body>
    <h1>Kats Agencies — Payment Receipt</h1>
    <p class="meta">Receipt #{{ $payment->id }} · Issued {{ now()->format('d M Y, H:i') }}</p>

    <table>
        <tr><td class="label">Tenant</td><td class="value">{{ $payment->tenancy->tenant->name }}</td></tr>
        <tr><td class="label">Property</td><td class="value">{{ $payment->tenancy->house->block->property->name }}</td></tr>
        <tr><td class="label">Unit</td><td class="value">Block {{ $payment->tenancy->house->block->name }} — {{ $payment->tenancy->house->house_number }}</td></tr>
        @if ($payment->rentCharge)
            <tr><td class="label">Rent Period</td><td class="value">{{ \Carbon\Carbon::create()->month($payment->rentCharge->period_month)->format('F') }} {{ $payment->rentCharge->period_year }}</td></tr>
        @endif
        <tr><td class="label">Payment Date</td><td class="value">{{ $payment->payment_date->format('d M Y') }}</td></tr>
        <tr><td class="label">Method</td><td class="value">{{ ucfirst($payment->method) }}</td></tr>
        <tr><td class="label">Reference</td><td class="value">{{ $payment->reference_number ?? '—' }}</td></tr>
    </table>

    <p class="amount">Amount Paid: KSh {{ number_format($payment->amount, 2) }}</p>
</body>
</html>
