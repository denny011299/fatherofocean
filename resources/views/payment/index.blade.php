@extends('layouts.app')
@section('title', 'Approval Pembayaran')

@section('content')
<div class="glass p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select select2" data-placeholder="Semua Status">
                <option value="">Semua</option>
                @foreach(['pending','approved','rejected'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-accent w-100">Filter</button></div>
    </form>
</div>

<div class="row g-3">
    @forelse($payments as $p)
    <div class="col-md-6 col-xl-4">
        <div class="glass p-4">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="fw-700 text-white">{{ $p->invoice?->displayInvoiceNo() }}</div>
                    <div class="text-muted-glass small">{{ $p->customer?->name }}</div>
                </div>
                <span class="badge-glass badge-status-{{ $p->status }}">{{ ucfirst($p->status) }}</span>
            </div>
            <div class="text-accent fw-800 fs-5 mb-2">Rp {{ number_format($p->amount, 0, ',', '.') }}</div>
            <div class="text-muted-glass small mb-1">Pengirim: {{ $p->sender_name ?? '-' }}</div>
            <div class="text-muted-glass small mb-3">Tanggal: {{ $p->created_at->format('d M Y H:i') }}</div>

            @if($p->proof_url)
            <div class="mb-3">
                <a href="{{ $p->proof_url }}" target="_blank" class="btn btn-glass btn-sm w-100">
                    <i class="bi bi-image me-1"></i>Lihat Bukti Transfer
                </a>
            </div>
            @endif

            @if($p->status === 'pending')
            <div class="d-flex gap-2">
                <button class="btn btn-accent btn-sm flex-grow-1 btn-approve" data-id="{{ $p->id }}">
                    <i class="bi bi-check-lg me-1"></i>Approve & Lunas
                </button>
                <button class="btn btn-danger-glow btn-sm flex-grow-1 btn-reject" data-id="{{ $p->id }}" style="animation:none">
                    <i class="bi bi-x-lg me-1"></i>Reject
                </button>
            </div>
            @endif

            @if($p->review_notes)
            <div class="glass-solid p-2 mt-2">
                <div class="text-muted-glass" style="font-size:.7rem">Catatan Review:</div>
                <div class="small text-white">{{ $p->review_notes }}</div>
            </div>
            @endif
        </div>
    </div>
    @empty
    <div class="col-12">
        <div class="glass p-5 text-center text-muted-glass">
            <i class="bi bi-credit-card display-4 d-block mb-2 opacity-50"></i>
            Belum ada pembayaran masuk.
        </div>
    </div>
    @endforelse
</div>

<div class="mt-3">{{ $payments->withQueryString()->links('vendor.pagination.bootstrap-5') }}</div>

{{-- Reject Modal --}}
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700">Tolak Pembayaran</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="rejectForm">
                    <input type="hidden" id="rejectPaymentId">
                    <div class="mb-3">
                        <label class="form-label">Alasan Penolakan</label>
                        <textarea name="review_notes" class="form-control" rows="3" required placeholder="Masukkan alasan..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger-glow w-100" style="animation:none">Tolak Pembayaran</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function(){
    var form = document.querySelector('form[method="GET"]');
    if(!form) return;
    var timer;
    form.querySelectorAll('input[type="text"], input[type="search"]').forEach(function(el){
        el.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(function(){ form.submit(); }, 400); });
    });
    form.querySelectorAll('select:not(.select2-hidden-accessible), input[type="date"]').forEach(function(el){
        el.addEventListener('change', function(){ form.submit(); });
    });
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof $ !== 'undefined') {
            $(form).find('.select2').on('change', function(){ form.submit(); });
        }
    });
    var submitBtn = form.querySelector('button:not([type="button"]):not([type="reset"])');
    if(submitBtn) { var col = submitBtn.closest('[class*="col-"]'); if(col) col.classList.add('d-none'); }
})();

document.addEventListener('DOMContentLoaded', function() {
    const csrf = '{{ csrf_token() }}';

    $('.btn-approve').on('click', function() {
        if (!confirm('Approve pembayaran ini?')) return;
        $.ajax({
            url: `/payment/${$(this).data('id')}/approve`,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: function(res) { showToast(res.message); setTimeout(() => location.reload(), 800); },
            error: function(xhr) { showToast(xhr.responseJSON?.message || 'Gagal', 'error'); }
        });
    });

    $('.btn-reject').on('click', function() {
        $('#rejectPaymentId').val($(this).data('id'));
        new bootstrap.Modal('#rejectModal').show();
    });

    $('#rejectForm').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: `/payment/${$('#rejectPaymentId').val()}/reject`,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            data: $(this).serialize(),
            success: function(res) { showToast(res.message); setTimeout(() => location.reload(), 800); },
            error: function(xhr) { showToast(xhr.responseJSON?.message || 'Gagal', 'error'); }
        });
    });
});
</script>
@endpush
