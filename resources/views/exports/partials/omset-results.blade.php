@if($rows->count() > 0)
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="glass stat-card">
            <div class="stat-icon" style="--stat-icon-color: var(--accent)"><i class="bi bi-box-seam"></i></div>
            <div class="stat-label">Total Kiriman</div>
            <div class="stat-value">{{ number_format($totals['shipment_count']) }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass stat-card">
            <div class="stat-icon" style="--stat-icon-color: rgba(10,34,64,.12)"><i class="bi bi-rulers"></i></div>
            <div class="stat-label">Total CBM</div>
            <div class="stat-value">{{ \App\Support\CbmFormat::format((float) $totals['total_cbm']) }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass stat-card">
            <div class="stat-icon" style="--stat-icon-color: #f59e0b"><i class="bi bi-speedometer"></i></div>
            <div class="stat-label">Total GW</div>
            <div class="stat-value">{{ number_format((float) $totals['total_gw'], 2) }} <small style="font-size:.6em">kg</small></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass stat-card">
            <div class="stat-icon" style="--stat-icon-color: #22c55e"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-label">Total Omset</div>
            <div class="stat-value" style="color:#22c55e">Rp {{ number_format((float) $totals['omset'], 0, ',', '.') }}</div>
        </div>
    </div>
</div>

@can('export omset')
<div class="d-flex gap-2 mb-4" id="omsetExportActions">
    <a href="{{ route('export.omset.excel', $exportQuery) }}" class="btn btn-sm omset-export-excel" style="background:rgba(34,197,94,.15);color:#22c55e;border:1px solid rgba(34,197,94,.3);border-radius:1.25rem;font-weight:600">
        <i class="bi bi-file-earmark-excel me-1"></i> Export Excel Detail
    </a>
    <a href="{{ route('export.omset.pdf', $exportQuery) }}" class="btn btn-sm omset-export-pdf" style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);border-radius:1.25rem;font-weight:600">
        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
    </a>
</div>
@endcan

<div class="glass p-0 overflow-hidden">
    <div class="p-3 d-flex align-items-center justify-content-between" style="border-bottom:1px solid var(--glass-border)">
        <h6 class="mb-0 fw-700" style="font-size:.9rem;color:var(--text-primary)">
            <i class="bi bi-table me-1" style="color:var(--accent)"></i>
            Data Omset — {{ $periodLabel }} {{ $rangeLabel }}
        </h6>
        <span class="badge-glass">{{ $rows->count() }} baris</span>
    </div>
    <div class="table-responsive">
        <table class="table table-glass mb-0">
            <thead>
                <tr>
                    <th style="width:50px">No</th>
                    <th>Periode</th>
                    <th>Marking</th>
                    <th class="text-end">Kiriman</th>
                    <th class="text-end">CBM</th>
                    <th class="text-end">GW (kg)</th>
                    <th class="text-end">Omset (Rp)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><span class="badge-glass omset-period-badge">{{ $row['period'] }}</span></td>
                    <td>{{ $row['marking'] }}</td>
                    <td class="text-end">{{ number_format($row['shipment_count']) }}</td>
                    <td class="text-end">{{ \App\Support\CbmFormat::format((float) $row['total_cbm']) }}</td>
                    <td class="text-end">{{ number_format((float) $row['total_gw'], 2) }}</td>
                    <td class="text-end fw-600" style="color:#22c55e">Rp {{ number_format((float) $row['omset'], 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:var(--glass-bg);font-weight:700;border-top:2px solid var(--accent)">
                    <td colspan="3" style="color:var(--text-primary)">TOTAL</td>
                    <td class="text-end" style="color:var(--text-primary)">{{ number_format($totals['shipment_count']) }}</td>
                    <td class="text-end" style="color:var(--text-primary)">{{ \App\Support\CbmFormat::format((float) $totals['total_cbm']) }}</td>
                    <td class="text-end" style="color:var(--text-primary)">{{ number_format((float) $totals['total_gw'], 2) }}</td>
                    <td class="text-end fw-700" style="color:#22c55e;font-size:1rem">Rp {{ number_format((float) $totals['omset'], 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@else
<div class="glass p-5 text-center">
    <i class="bi bi-inbox" style="font-size:2rem;opacity:.4;color:var(--text-muted)"></i>
    <p class="mt-3 mb-0" style="color:var(--text-muted)">Tidak ada data omset untuk periode yang dipilih.</p>
    <p class="mb-0 mt-2 small" style="color:var(--text-muted)">Coba <strong>Tahun ini</strong>, <strong>Bulan ini</strong>, atau <strong>Kustom</strong> (mis. Mei 2026) jika datanya di luar 7/30 hari terakhir.</p>
</div>
@endif
