<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #17324D; }
        h1 { font-size: 16px; color: #17324D; margin-bottom: 2px; }
        p.meta { color: #64748B; margin-top: 0; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #E2E8F0; padding: 5px 8px; text-align: left; }
        th { background-color: #F8FAFC; color: #64748B; }
    </style>
</head>
<body>
    <h1>Kats Agencies — {{ $title }}</h1>
    <p class="meta">Generated {{ now()->format('d M Y, H:i') }}</p>
    <table>
        <thead>
            <tr>
                @foreach ($report['columns'] as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) }}">No data for this selection.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
