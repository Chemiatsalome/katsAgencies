<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Tenancy Agreement</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #17324D; line-height: 1.5; }
        h1 { font-size: 18px; color: #17324D; margin-bottom: 2px; }
        p.meta { color: #64748B; margin-top: 0; margin-bottom: 20px; }
        .body { white-space: pre-wrap; }
    </style>
</head>
<body>
    <h1>Kats Agencies — Tenancy Agreement</h1>
    <p class="meta">Generated {{ now()->format('d M Y, H:i') }}</p>
    <div class="body">{!! nl2br(e($body)) !!}</div>
</body>
</html>
