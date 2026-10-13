<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Summary</title>
    <style>
        body { font-family: Arial, sans-serif; color: #0f172a; }
        .card { border: 1px solid #cbd5e1; border-radius: 12px; padding: 12px; margin-bottom: 10px; }
        h1 { margin-bottom: 16px; }
    </style>
</head>
<body>
<h1>Dashboard Summary</h1>
<div class="card">Total Device: {{ $devices }}</div>
<div class="card">Connected Device: {{ $connected }}</div>
<div class="card">Total Kapal: {{ $vessels }}</div>
<div class="card">Total Pelabuhan: {{ $ports }}</div>
</body>
</html>
