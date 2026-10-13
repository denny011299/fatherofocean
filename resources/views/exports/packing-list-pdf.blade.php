<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 11px; color: #333; }
    h1 { font-size: 18px; color: #0f172a; }
    .header { border-bottom: 2px solid #34d399; padding-bottom: 10px; margin-bottom: 15px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0f172a; color: #fff; padding: 6px 8px; text-align: left; font-size: 10px; }
    td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
    .summary { margin-top: 15px; background: #f0fdf4; padding: 10px; border-radius: 8px; }
</style>
</head><body>
    <div class="header">
        <h1>PACKING LIST</h1>
        <div>Container: {{ $container->partai }} ({{ $container->type }}) | No: {{ $container->container_no ?? '-' }}</div>
        <div>Port: {{ $container->port?->name ?? '-' }} | Departure: {{ $container->departure_date?->format('d M Y') ?? '-' }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>{{ __('barang.col_no_index') }}</th>
                <th>Marking</th>
                <th>CTNS</th>
                <th>PCS</th>
                <th>Material</th>
                <th>CBM</th>
                <th>GW (kg)</th>
                <th>Destination</th>
            </tr>
        </thead>
        <tbody>
            @foreach($container->barangs as $i => $b)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $b->no_index }}</td>
                <td>{{ $b->marking ?? '-' }}</td>
                <td>{{ $b->ctns }}</td>
                <td>{{ $b->pcs }}</td>
                <td>{{ $b->material ?? '-' }}</td>
                <td>{{ \App\Support\CbmFormat::format((float) $b->total_cbm) }}</td>
                <td>{{ $b->total_gross_weight }}</td>
                <td>{{ $b->destination ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <strong>Total Items:</strong> {{ $container->barangs->count() }} |
        <strong>Total CBM:</strong> {{ \App\Support\CbmFormat::format(\App\Support\CbmFormat::truncate((float) $container->barangs->sum('total_cbm'))) }} |
        <strong>Total GW:</strong> {{ number_format($container->barangs->sum('total_gross_weight'), 2) }} kg |
        <strong>Capacity:</strong> {{ $container->capacity_percent }}%
    </div>
</body></html>
