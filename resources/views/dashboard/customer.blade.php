@extends('layouts.app')
@section('title', __('dashboard.portal_title'))

@section('content')
<div class="dashboard-stats-grid mb-4">
    <div class="glass stat-card stat-card-premium stat-card-blue">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-blue">
                <i class="bi bi-box-seam"></i>
            </div>
            <span class="stat-pill stat-pill-blue">Cargo</span>
        </div>
        <div>
            <div class="stat-label">Total Barang Saya</div>
            <div class="stat-value">{{ $totalBarang }}</div>
            <div class="stat-change up mt-2"><i class="bi bi-boxes me-1"></i> Total kargo terdaftar</div>
        </div>
    </div>
    <div class="glass stat-card stat-card-premium stat-card-amber">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-amber">
                <i class="bi bi-receipt"></i>
            </div>
            <span class="stat-pill stat-pill-amber">Outstanding</span>
        </div>
        <div>
            <div class="stat-label">Tagihan Belum Dibayar</div>
            <div class="stat-value text-warning">{{ $unpaidInvoices }}</div>
            <div class="stat-change down mt-2"><i class="bi bi-exclamation-circle me-1"></i> Menunggu pelunasan</div>
        </div>
    </div>
    <div class="glass stat-card stat-card-premium stat-card-indigo">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-indigo">
                <i class="bi bi-send-check"></i>
            </div>
            <span class="stat-pill stat-pill-blue">Delivered</span>
        </div>
        <div>
            <div class="stat-label">Barang Terkirim</div>
            <div class="stat-value text-accent">{{ $deliveredCount }}</div>
            <div class="stat-change up mt-2"><i class="bi bi-check-circle-fill me-1"></i> Selesai diterima</div>
        </div>
    </div>
</div>

@if(!empty($portalMarking) || !empty($portalMarkings))
@include('_partials.portal-markings')
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="glass p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-700 text-white mb-0"><i class="bi bi-box-seam me-2"></i>Barang Saya</h6>
                <a href="{{ route('tracking.index') }}" class="btn btn-accent btn-sm">
                    <i class="bi bi-radar me-1"></i>{{ __('nav.item_tracking') }}
                </a>
            </div>
            <div class="table-responsive customer-barang-table-wrap">
                <table class="table-glass">
                    <thead>
                        <tr>
                            <th>{{ __('dashboard.col_no_index') }}</th>
                            <th>{{ __('dashboard.col_marking') }}</th>
                            <th>{{ __('barang.col_status') }}</th>
                            <th>{{ __('barang.col_cbm') }}</th>
                            <th>{{ __('barang.col_coly') }}</th>
                            <th class="col-aksi">{{ __('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($myBarangs as $b)
                        @php
                            $barangInvoice = $b->invoiceItems->first()?->invoice;
                            $invoicePdfUrl = $barangInvoice ? route('billing.invoice.pdf', $barangInvoice) : '';
                            $publicTrackUrl = route('track.show', ['noIndex' => $b->no_index]);
                        @endphp
                        <tr>
                            <td class="fw-600 text-white">{{ $b->no_index }}</td>
                            <td class="small">{{ $b->marking ?: '—' }}</td>
                            <td><span class="badge-glass {{ $b->status_badge }}">{{ ucfirst($b->status) }}</span></td>
                            <td>{{ \App\Support\CbmFormat::format((float) $b->total_cbm) }}</td>
                            <td>{{ $b->ctns !== null && $b->ctns !== '' ? (int) $b->ctns : '—' }}</td>
                            <td class="col-aksi">
                                <div class="customer-barang-actions">
                                    <a href="{{ $publicTrackUrl }}" class="btn btn-glass btn-sm" title="{{ __('dashboard.action_track') }}">
                                        <i class="bi bi-geo-alt"></i>
                                    </a>
                                    @if($b->status === \App\Models\Barang::STATUS_BONGKAR && !$b->rating)
                                    <button type="button" class="btn btn-accent btn-sm btn-rate" data-id="{{ $b->id }}" data-invoice-pdf="{{ $invoicePdfUrl }}" title="{{ __('dashboard.action_rate') }}">
                                        <i class="bi bi-star"></i>
                                    </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-muted-glass py-4">Belum ada barang.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="glass p-4">
            <h6 class="fw-700 text-white mb-3"><i class="bi bi-receipt me-2"></i>Tagihan Terbaru</h6>
            @forelse($myInvoices as $inv)
            @php
                $paidAmount = $inv->payments->where('status', 'approved')->sum('amount');
                $balance = max(0, (float) $inv->grand_total - $paidAmount);
                $invStatus = \App\Support\InvoiceStatusPresenter::forInvoice($inv, $paidAmount, $balance);
            @endphp
            <div class="glass-solid p-3 mb-2">
                <div class="invoice-no-row">
                    <span class="finance-invoice-no" title="{{ $inv->displayInvoiceNo() }}">{{ $inv->displayInvoiceNo() }}</span>
                    <span class="{{ $invStatus['class'] }} invoice-no-row-badge">{{ $invStatus['label'] }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-end gap-2 mt-1">
                    <div>
                        <div class="text-accent fw-800">Rp {{ number_format($inv->grand_total, 0, ',', '.') }}</div>
                        <div class="text-muted-glass" style="font-size:.72rem">{{ __('finance.col_due_date') }}: {{ \App\Support\InvoiceStatusPresenter::formatDueDate($inv->due_date) }}</div>
                    </div>
                    <a href="{{ route('billing.invoice.pdf', $inv) }}" class="btn btn-glass btn-download-invoice flex-shrink-0">
                        Download
                    </a>
                </div>
            </div>
            @empty
            <p class="text-muted-glass small">Belum ada tagihan.</p>
            @endforelse
            <a href="{{ route('billing.index') }}" class="btn btn-glass btn-sm w-100 mt-2">Lihat Semua Tagihan</a>
        </div>
    </div>
</div>

{{-- Rating Modal --}}
<div class="modal fade" id="ratingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700">Beri Rating</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="ratingForm">
                    <input type="hidden" id="ratingBarangId">
                    <div class="text-center mb-3">
                        <div class="star-rating">
                            @for($i = 5; $i >= 1; $i--)
                            <input type="radio" name="score" value="{{ $i }}" id="star{{ $i }}">
                            <label for="star{{ $i }}"><i class="bi bi-star-fill"></i></label>
                            @endfor
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Review (opsional)</label>
                        <textarea name="review" class="form-control" rows="3" placeholder="Tulis pengalaman Anda..."></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-accent w-100">Kirim Rating</button>
                        <a href="#" id="ratingInvoiceDownload" class="btn btn-glass w-100 d-none" target="_blank" rel="noopener">
                            <i class="bi bi-download me-1"></i>{{ __('dashboard.action_download_invoice') }}
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    $('.btn-rate').on('click', function() {
        $('#ratingBarangId').val($(this).data('id'));
        const pdfUrl = $(this).attr('data-invoice-pdf') || '';
        const $dl = $('#ratingInvoiceDownload');
        if (pdfUrl) {
            $dl.attr('href', pdfUrl).removeClass('d-none');
        } else {
            $dl.attr('href', '#').addClass('d-none');
        }
        new bootstrap.Modal('#ratingModal').show();
    });

    $('#ratingForm').on('submit', function(e) {
        e.preventDefault();
        const id = $('#ratingBarangId').val();
        $.ajax({
            url: `/rating/${id}`,
            method: 'POST',
            data: $(this).serialize() + '&_token={{ csrf_token() }}',
            success: function(res) {
                bootstrap.Modal.getInstance('#ratingModal').hide();
                showToast(res.message);
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                showToast(xhr.responseJSON?.message || 'Gagal mengirim rating', 'error');
            }
        });
    });
});
</script>
@endpush
