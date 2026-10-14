@extends('layouts.app')
@section('title', __('dashboard.finance_title'))

@section('content')
<div class="bento-grid mb-4">
    <div class="glass stat-card stat-card-premium stat-card-amber">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-amber">
                <i class="bi bi-clock-history"></i>
            </div>
            <span class="stat-pill stat-pill-amber">Review</span>
        </div>
        <div>
            <div class="stat-label">Pending Payments</div>
            <div class="stat-value text-warning">{{ $pendingPayments }}</div>
            <div class="stat-change mt-2"><i class="bi bi-clock me-1"></i> Menunggu review</div>
        </div>
    </div>
    <div class="glass stat-card stat-card-premium stat-card-indigo">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-indigo">
                <i class="bi bi-receipt"></i>
            </div>
            <span class="stat-pill stat-pill-indigo">Invoice</span>
        </div>
        <div>
            <div class="stat-label">Total Invoice</div>
            <div class="stat-value">{{ number_format($totalInvoices) }}</div>
            <div class="stat-change mt-2"><i class="bi bi-files me-1"></i> Seluruh tagihan</div>
        </div>
    </div>
    <div class="glass stat-card stat-card-premium stat-card-rose">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-rose">
                <i class="bi bi-exclamation-octagon"></i>
            </div>
            <span class="stat-pill stat-pill-amber">Unpaid</span>
        </div>
        <div>
            <div class="stat-label">Total Belum Dibayar</div>
            <div class="stat-value text-danger" style="font-size: 1.45rem;">Rp {{ number_format($unpaidTotal, 0, ',', '.') }}</div>
            <div class="stat-change down mt-2"><i class="bi bi-arrow-down-right me-1"></i> Piutang tertunda</div>
        </div>
    </div>
    <div class="glass stat-card stat-card-premium stat-card-blue">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-blue">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <span class="stat-pill stat-pill-blue">Settled</span>
        </div>
        <div>
            <div class="stat-label">Total Terbayar</div>
            <div class="stat-value text-accent" style="font-size: 1.45rem;">Rp {{ number_format($paidTotal, 0, ',', '.') }}</div>
            <div class="stat-change up mt-2"><i class="bi bi-arrow-up-right me-1"></i> Pembayaran lunas</div>
        </div>
    </div>
</div>

<div class="glass p-4">
    <div class="d-flex align-items-center gap-2 mb-3">
        <div class="stat-icon-squircle squircle-royal" style="width:36px;height:36px;font-size:1rem;border-radius:10px;">
            <i class="bi bi-credit-card-2-front"></i>
        </div>
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)">Pembayaran Terbaru</h6>
    </div>
    <div class="table-responsive">
        <table class="table-glass">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                    <th>Tanggal</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recentPayments as $p)
                <tr>
                    <td class="fw-600 text-white">{{ $p->invoice?->displayInvoiceNo() }}</td>
                    <td>{{ $p->customer?->name }}</td>
                    <td>Rp {{ number_format($p->amount, 0, ',', '.') }}</td>
                    <td><span class="badge-glass badge-status-{{ $p->status }}">{{ ucfirst($p->status) }}</span></td>
                    <td class="text-muted-glass">{{ $p->created_at->format('d M Y') }}</td>
                    <td>
                        @if($p->status === 'pending')
                        <a href="{{ route('finance.index') }}" class="btn btn-accent btn-sm">Review</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted-glass py-4">Belum ada pembayaran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
