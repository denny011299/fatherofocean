<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px; }
    h1 { font-size: 20px; color: #0f172a; margin-bottom: 5px; }
    .header { border-bottom: 2px solid #818cf8; padding-bottom: 10px; margin-bottom: 20px; }
    .header-sub { color: #64748b; font-size: 11px; }
    .period-info { background: #f8fafc; padding: 10px 14px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #818cf8; }
    .period-info strong { color: #0f172a; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th { background: #0f172a; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
    th:nth-child(n+4) { text-align: right; }
    td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
    td:nth-child(n+4) { text-align: right; }
    tr:nth-child(even) { background: #f8fafc; }
    .total-row { font-weight: bold; background: #f0fdf4 !important; border-top: 2px solid #0f172a; }
    .total-row td { padding: 10px; font-size: 12px; }
    .grand-total-box { text-align: right; margin-top: 10px; }
    .grand-total-box .label { font-size: 11px; color: #64748b; text-transform: uppercase; }
    .grand-total-box .value { font-size: 22px; font-weight: bold; color: #10b981; }
    .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #94a3b8; text-align: center; }
    .page-break { page-break-after: always; }
</style>
</head><body>
    <div class="header">
        <h1>LAPORAN OMSET</h1>
        <div class="header-sub">{{ config('app.name', 'EXPORT') }} — Sistem Induk</div>
    </div>

    <div class="period-info">
        <strong>Tipe Periode:</strong> {{ $periodLabel }}<br>
        <strong>Rentang:</strong> {{ $rangeLabel }}<br>
        <strong>Dicetak:</strong> {{ now()->format('d M Y H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:40px">No</th>
                <th>Periode</th>
                <th>Marking</th>
                <th>Kiriman</th>
                <th>CBM</th>
                <th>GW (kg)</th>
                <th>Omset (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $row['period'] }}</td>
                <td>{{ $row['marking'] }}</td>
                <td>{{ $row['shipment_count'] }}</td>
                <td>{{ \App\Support\CbmFormat::format((float) $row['total_cbm']) }}</td>
                <td>{{ number_format((float) $row['total_gw'], 2, '.', ',') }}</td>
                <td>Rp {{ number_format((float) $row['omset'], 0, ',', '.') }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3">TOTAL</td>
                <td>{{ $totals['shipment_count'] }}</td>
                <td>{{ \App\Support\CbmFormat::format((float) $totals['total_cbm']) }}</td>
                <td>{{ number_format((float) $totals['total_gw'], 2, '.', ',') }}</td>
                <td>Rp {{ number_format((float) $totals['omset'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="grand-total-box">
        <div class="label">Total Omset</div>
        <div class="value">Rp {{ number_format((float) $totals['omset'], 0, ',', '.') }}</div>
    </div>

    <div class="footer">
        Dokumen ini dibuat secara otomatis oleh sistem {{ config('app.name', 'EXPORT') }} pada {{ now()->format('d M Y H:i:s') }}.
    </div>
</body></html>
