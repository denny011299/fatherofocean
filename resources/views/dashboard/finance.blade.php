@extends('layouts.app')
@section('title', __('dashboard.finance_title'))

@section('content')
<div class="bento-grid mb-4">
    <div class="glass stat-card">
        <div class="stat-label">Pending Payments</div>
        <div class="stat-value text-warning">{{ $pendingPayments }}</div>
        <div class="stat-change"><i class="bi bi-clock"></i> Menunggu review</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">Total Invoice</div>
        <div class="stat-value">{{ number_format($totalInvoices) }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">Total Belum Dibayar</div>
        <div class="stat-value text-danger">Rp {{ number_format($unpaidTotal, 0, ',', '.') }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">Total Terbayar</div>
        <div class="stat-value text-accent">Rp {{ number_format($paidTotal, 0, ',', '.') }}</div>
    </div>
</div>

<div class="glass p-4">
    <h6 class="fw-700 text-white mb-3">Pembayaran Terbaru</h6>
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
