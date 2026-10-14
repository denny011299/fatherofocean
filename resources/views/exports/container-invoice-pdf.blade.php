<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 12px; color: #333; }
    h1 { font-size: 20px; color: #0f172a; margin-bottom: 5px; }
    .header { border-bottom: 2px solid #34d399; padding-bottom: 10px; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th { background: #0f172a; color: #fff; padding: 8px; text-align: left; font-size: 11px; }
    td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
    .total-row { font-weight: bold; background: #f0fdf4; }
</style>
</head><body>
    <div class="header">
        <h1>INVOICE{{ isset($markingLabel) ? ' — ' . $markingLabel : ' ALL' }} — {{ $container->partai }}</h1>
        <div>{{ config('app.name', 'EXPORT') }} — Sistem Induk</div>
    </div>

    <table style="width:100%;margin-bottom:20px;border:none">
        <tr>
            <td style="border:none;width:50%;vertical-align:top">
                <strong>Container:</strong> {{ $container->container_no ?? '-' }}<br>
                <strong>No BL:</strong> {{ $container->no_bl ?? '-' }}<br>
                <strong>Partai:</strong> {{ $container->partai }}
            </td>
            <td style="border:none;width:50%;vertical-align:top;text-align:right">
                <strong>Load Date:</strong> {{ $container->load_date?->format('d M Y') ?? '-' }}<br>
                <strong>ETD:</strong> {{ $container->etd?->format('d M Y') ?? '-' }}<br>
                <strong>ETA:</strong> {{ $container->eta?->format('d M Y') ?? '-' }}
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>{{ __('barang.col_no_index') }}</th>
                <th>Marking</th>
                <th>Customer</th>
                <th>CBM</th>
                <th>Harga/CBM</th>
                <th>Extra</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($container->barangs as $i => $b)
            @php
                $lineTotal = ($b->total_cbm * $b->price_per_cbm) + ($b->extra_charge ?? 0);
            @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $b->no_index }}</td>
                <td>{{ $b->marking ?? '-' }}</td>
                <td>{{ $b->customer?->name ?? '-' }}</td>
                <td>{{ \App\Support\CbmFormat::format((float) $b->total_cbm) }}</td>
                <td>Rp {{ number_format($b->price_per_cbm, 0, ',', '.') }}</td>
                <td>Rp {{ number_format($b->extra_charge ?? 0, 0, ',', '.') }}</td>
                <td>Rp {{ number_format($lineTotal, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $totalCbm = $container->barangs->sum('total_cbm');
        $totalExtra = $container->barangs->sum('extra_charge');
        $grandTotal = $container->barangs->sum(fn ($b) => ($b->total_cbm * $b->price_per_cbm) + ($b->extra_charge ?? 0));
    @endphp

    <table style="width:300px;float:right">
        <tr><td>Total CBM</td><td style="text-align:right">{{ \App\Support\CbmFormat::format((float) $totalCbm) }}</td></tr>
        <tr><td>Extra Charges</td><td style="text-align:right">Rp {{ number_format($totalExtra, 0, ',', '.') }}</td></tr>
        <tr class="total-row"><td><strong>Grand Total</strong></td><td style="text-align:right"><strong>Rp {{ number_format($grandTotal, 0, ',', '.') }}</strong></td></tr>
    </table>
</body></html>
