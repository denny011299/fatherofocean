@extends('layouts.app')
@section('title', 'Tagihan Saya')

@section('content')
@if(!empty($portalMarking) || !empty($portalMarkings))
@include('_partials.portal-markings')
@endif
<div class="row g-3">
    @forelse($invoices as $inv)
    @php
        $paidAmount = $inv->payments->where('status', 'approved')->sum('amount');
        $balance = max(0, (float) $inv->grand_total - $paidAmount);
        $invStatus = \App\Support\InvoiceStatusPresenter::forInvoice($inv, $paidAmount, $balance);
    @endphp
    <div class="col-md-6">
        <div class="glass p-4">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="fw-700 text-white">{{ $inv->displayInvoiceNo() }}</div>
                </div>
                <span class="{{ $invStatus['class'] }}">{{ $invStatus['label'] }}</span>
            </div>
            <div class="text-accent fw-800 fs-5 mb-2">Rp {{ number_format($inv->grand_total, 0, ',', '.') }}</div>
            @if((float) $inv->claim_deduction_total > 0)
            <div class="small text-warning mb-2">Potongan klaim: -Rp {{ number_format((float) $inv->claim_deduction_total, 0, ',', '.') }}</div>
            @endif
            <div class="small mb-3 fw-600">{{ __('finance.col_due_date') }}: {{ \App\Support\InvoiceStatusPresenter::formatDueDate($inv->due_date) }}</div>

            {{-- Items per pengiriman (no_index) --}}
            @php
                $shipmentGroups = $inv->items->groupBy(
                    static fn ($item) => trim((string) ($item->barang?->no_index ?? '')) ?: '-',
                );
            @endphp
            <div class="glass-solid p-2 mb-3" style="max-height:150px;overflow-y:auto">
                @foreach($shipmentGroups as $noIndex => $groupItems)
                @if($shipmentGroups->count() > 1)
                <div class="small fw-600 text-muted-glass mb-1 pt-1">{{ __('finance.col_no_index') }}: {{ $noIndex }}</div>
                @endif
                @foreach($groupItems as $item)
                <div class="d-flex justify-content-between small py-1" style="border-bottom:1px solid rgba(255,255,255,.04)">
                    <span class="text-white">{{ $item->barang?->no_index }}</span>
                    <span class="text-accent">Rp {{ number_format($item->line_total, 0, ',', '.') }}</span>
                </div>
                @endforeach
                @endforeach
            </div>

            {{-- Payment History --}}
            @if($inv->payments->isNotEmpty())
            <div class="mb-3">
                <div class="text-muted-glass small fw-600 mb-1">Riwayat Bayar:</div>
                @foreach($inv->payments as $pay)
                <div class="glass-solid p-2 mb-1 small">
                    <span class="badge-glass badge-status-{{ $pay->status }}">{{ ucfirst($pay->status) }}</span>
                    Rp {{ number_format($pay->amount, 0, ',', '.') }} — {{ $pay->created_at->format('d/m/Y') }}
                </div>
                @endforeach
            </div>
            @endif

            @if(!\App\Support\InvoiceStatusPresenter::isFullyPaid($paidAmount, $balance))
            @can('upload payment proof')
            <button class="btn btn-accent btn-sm w-100 btn-upload-proof" data-id="{{ $inv->id }}" data-total="{{ $inv->grand_total }}">
                <i class="bi bi-upload me-1"></i>Upload Bukti Transfer
            </button>
            @endcan
            @endif
            @can('create claim')
            <a href="{{ route('claims.index') }}" class="btn btn-glass btn-sm w-100 mt-2">
                <i class="bi bi-shield-exclamation me-1"></i>{{ __('claim.new') }}
            </a>
            @endcan
        </div>
    </div>
    @empty
    <div class="col-12">
        <div class="glass p-5 text-center text-muted-glass">
            <i class="bi bi-receipt display-4 d-block mb-2 opacity-50"></i>
            Belum ada tagihan.
        </div>
    </div>
    @endforelse
</div>

<div class="mt-3">{{ $invoices->withQueryString()->links('vendor.pagination.bootstrap-5') }}</div>

{{-- Upload Payment Proof Modal --}}
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700">Upload Bukti Transfer</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="invoice_id" id="uploadInvoiceId">
                    <div class="mb-3">
                        <label class="form-label">Nominal Transfer</label>
                        <input type="number" name="amount" class="form-control" id="uploadAmount" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Pengirim</label>
                        <input type="text" name="sender_name" class="form-control" placeholder="Nama di rekening pengirim" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('finance.label_notes') }}</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="1000" placeholder="{{ __('finance.notes_placeholder') }}"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bukti Transfer</label>
                        <div class="drop-zone" id="dropZone">
                            <i class="bi bi-cloud-arrow-up display-6 text-muted-glass"></i>
                            <p class="text-muted-glass small mt-2 mb-0">Drag & drop atau klik untuk upload</p>
                            <p class="text-muted-glass" style="font-size:.7rem">JPG, PNG, PDF (Max 5MB)</p>
                            <input type="file" name="proof_file" class="d-none" id="proofInput" accept=".jpg,.jpeg,.png,.webp,.pdf" required
                                data-compress-image="true" data-compress-profile="payment">
                        </div>
                        <div id="filePreview" class="mt-2 d-none text-center"></div>
                    </div>
                    <button type="submit" class="btn btn-accent w-100 py-2">
                        <i class="bi bi-send me-1"></i>Kirim Bukti Pembayaran
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrf = '{{ csrf_token() }}';
    const dropZone = document.getElementById('dropZone');
    const proofInput = document.getElementById('proofInput');

    $('.btn-upload-proof').on('click', function() {
        $('#uploadInvoiceId').val($(this).data('id'));
        $('#uploadAmount').val($(this).data('total'));
        $('#filePreview').addClass('d-none');
        new bootstrap.Modal('#uploadModal').show();
    });

    // Drag & Drop
    dropZone.addEventListener('click', () => proofInput.click());
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            proofInput.files = e.dataTransfer.files;
            proofInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    proofInput.addEventListener('change', async (e) => {
        if (window.waitForImageCompression) {
            await window.waitForImageCompression();
        }
        if (e.target.files.length) {
            previewFile(e.target.files[0]);
        }
    });

    function previewFile(file) {
        const preview = $('#filePreview').removeClass('d-none');
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => preview.html(`<img src="${e.target.result}" class="preview rounded-3" style="max-width:200px;max-height:200px">`);
            reader.readAsDataURL(file);
        } else {
            preview.html(`<div class="glass-solid p-3"><i class="bi bi-file-pdf me-2"></i>${file.name}</div>`);
        }
    }

    // Submit
    $('#uploadForm').on('submit', async function(e) {
        e.preventDefault();
        if (window.waitForImageCompression) {
            await window.waitForImageCompression();
        }
        const fd = new FormData(this);
        fd.append('_token', csrf);
        $.ajax({
            url: '{{ route("billing.upload") }}',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(res) {
                showToast(res.message);
                bootstrap.Modal.getInstance('#uploadModal').hide();
                setTimeout(() => location.reload(), 800);
            },
            error: function(xhr) { showToast(xhr.responseJSON?.message || 'Gagal upload', 'error'); }
        });
    });
});
</script>
@endpush
