@extends('layouts.app')
@section('title', __('claim.title'))

@section('content')
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="glass stat-card stat-card-premium stat-card-blue">
            <div class="stat-card-header">
                <div class="stat-icon-squircle squircle-blue">
                    <i class="bi bi-shield-shaded"></i>
                </div>
                <span class="stat-pill stat-pill-blue">Semua</span>
            </div>
            <div>
                <div class="stat-label">Total Klaim</div>
                <div class="stat-value">{{ $stats['total'] }}</div>
                <div class="stat-change mt-2"><i class="bi bi-files me-1"></i> Seluruh pengajuan</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass stat-card stat-card-premium stat-card-amber">
            <div class="stat-card-header">
                <div class="stat-icon-squircle squircle-amber">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <span class="stat-pill stat-pill-amber">Pending</span>
            </div>
            <div>
                <div class="stat-label">{{ __('claim.status_pending') }}</div>
                <div class="stat-value text-warning">{{ $stats['pending'] }}</div>
                <div class="stat-change mt-2"><i class="bi bi-clock me-1"></i> Menunggu review</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass stat-card stat-card-premium stat-card-emerald">
            <div class="stat-card-header">
                <div class="stat-icon-squircle squircle-emerald">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <span class="stat-pill stat-pill-blue">Approved</span>
            </div>
            <div>
                <div class="stat-label">{{ __('claim.status_approved') }}</div>
                <div class="stat-value text-success">{{ $stats['approved'] }}</div>
                <div class="stat-change up mt-2"><i class="bi bi-check-all me-1"></i> Disetujui</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass stat-card stat-card-premium stat-card-rose">
            <div class="stat-card-header">
                <div class="stat-icon-squircle squircle-rose">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <span class="stat-pill stat-pill-amber">Rejected</span>
            </div>
            <div>
                <div class="stat-label">{{ __('claim.status_rejected') }}</div>
                <div class="stat-value text-danger">{{ $stats['rejected'] }}</div>
                <div class="stat-change down mt-2"><i class="bi bi-slash-circle me-1"></i> Ditolak</div>
            </div>
        </div>
    </div>
</div>

<div class="glass p-3 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
        <form method="GET" class="row g-2 align-items-end flex-grow-1">
            <div class="col-md-6">
                <label class="form-label">{{ __('common.search') }}</label>
                <input type="text" name="search" class="form-control" placeholder="{{ __('claim.search_placeholder') }}" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('barang.col_status') }}</label>
                <select name="status" class="form-select select2" data-placeholder="{{ __('claim.filter_all_status') }}">
                    <option value="">{{ __('claim.filter_all_status') }}</option>
                    @foreach([\App\Models\Claim::STATUS_PENDING, \App\Models\Claim::STATUS_APPROVED, \App\Models\Claim::STATUS_REJECTED] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Models\Claim::statusLabel($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('claim.type') }}</label>
                <select name="type" class="form-select select2" data-placeholder="{{ __('claim.filter_all_types') }}">
                    <option value="">{{ __('claim.filter_all_types') }}</option>
                    @foreach(\App\Models\Claim::TYPES as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ \App\Models\Claim::typeLabel($type) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
        @if($canCreateClaim)
        <button type="button" class="btn btn-accent" id="btnOpenClaimModal">
            <i class="bi bi-plus-circle-fill me-1"></i>{{ __('claim.new') }}
        </button>
        @endif
    </div>
</div>

<div class="row g-3">
    @forelse($claims as $claim)
    <div class="col-md-6 col-xl-4">
        <div class="glass p-4 h-100">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <div class="fw-700 text-white">{{ $claim->invoice?->displayInvoiceNo() ?? '-' }}</div>
                    <div class="text-muted-glass small">{{ $claim->invoice?->customer?->name ?? $claim->submitter?->name ?? '-' }}</div>
                </div>
                <span class="badge-glass {{ \App\Models\Claim::statusBadgeClass($claim->status) }}">{{ \App\Models\Claim::statusLabel($claim->status) }}</span>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="badge-glass">{{ \App\Models\Claim::typeLabel($claim->claim_type) }}</span>
                <span class="text-accent fw-800">Rp {{ number_format((float) $claim->amount, 0, ',', '.') }}</span>
            </div>

            <div class="small text-white mb-3" style="min-height:48px">{{ $claim->notes }}</div>

            <div class="small text-muted-glass mb-1">{{ __('claim.submitted_by') }}: {{ $claim->submitter?->name ?? '-' }}</div>
            <div class="small text-muted-glass mb-3">{{ $claim->created_at?->format('d M Y H:i') }}</div>

            @if($claim->proof_url)
            <div class="mb-3">
                <div class="small text-muted-glass mb-1">
                    <i class="bi {{ $claim->isProofVideo() ? 'bi-camera-video' : 'bi-image' }} me-1"></i>
                    {{ $claim->isProofVideo() ? __('claim.proof_video') : __('claim.proof_photo') }}
                </div>
                @if($claim->isProofVideo())
                <video src="{{ $claim->proof_url }}" controls preload="metadata" class="w-100 rounded-3 claim-proof-video" style="max-height:200px;background:#000"></video>
                @else
                <button type="button" class="btn btn-glass btn-sm w-100 p-0 border-0 claim-proof-thumb-btn"
                    data-proof-url="{{ $claim->proof_url }}" title="{{ __('claim.preview_proof') }}">
                    <img src="{{ $claim->proof_url }}" alt="{{ __('claim.proof_photo') }}" class="w-100 rounded-3" style="max-height:140px;object-fit:cover">
                </button>
                @endif
            </div>
            @endif

            @if($claim->review_notes)
            @php
                $reviewNoteTone = match ($claim->status) {
                    \App\Models\Claim::STATUS_REJECTED => 'claim-review-note--rejected',
                    \App\Models\Claim::STATUS_APPROVED => 'claim-review-note--approved',
                    default => '',
                };
            @endphp
            <div class="claim-review-note mb-3 {{ $reviewNoteTone }}">
                <div class="claim-review-note__head">
                    <span class="claim-review-note__icon"><i class="bi bi-chat-left-text-fill"></i></span>
                    <span class="claim-review-note__title">{{ __('claim.review_notes') }}</span>
                </div>
                <div class="claim-review-note__body">{{ $claim->review_notes }}</div>
                <div class="claim-review-note__meta">
                    <span class="claim-review-note__reviewer">
                        <i class="bi bi-person-check"></i>
                        <span>{{ __('claim.reviewed_by') }} <strong>{{ $claim->reviewer?->name ?? '-' }}</strong></span>
                    </span>
                    @if($claim->reviewed_at)
                    <span class="claim-review-note__date">
                        <i class="bi bi-clock"></i>
                        {{ $claim->reviewed_at->format('d M Y · H:i') }}
                    </span>
                    @endif
                </div>
            </div>
            @endif

            @if($claim->status === \App\Models\Claim::STATUS_PENDING && ($canApproveClaim || $canRejectClaim))
            <div class="d-flex gap-2">
                @if($canApproveClaim)
                <button type="button" class="btn btn-accent btn-sm flex-grow-1 btn-approve-claim" data-id="{{ $claim->id }}">
                    <i class="bi bi-check-lg me-1"></i>{{ __('claim.approve') }}
                </button>
                @endif
                @if($canRejectClaim)
                <button type="button" class="btn btn-danger-glow btn-sm flex-grow-1 btn-reject-claim" data-id="{{ $claim->id }}" style="animation:none">
                    <i class="bi bi-x-lg me-1"></i>{{ __('claim.reject') }}
                </button>
                @endif
            </div>
            @endif

            @if($claim->status === \App\Models\Claim::STATUS_PENDING && (int) $claim->user_id === (int) auth()->id())
            <div class="d-flex gap-2 {{ ($canApproveClaim || $canRejectClaim) ? 'mt-2' : '' }}">
                <button type="button" class="btn btn-glass btn-sm flex-grow-1 btn-delete-claim" data-id="{{ $claim->id }}">
                    <i class="bi bi-trash me-1"></i>{{ __('claim.delete') }}
                </button>
            </div>
            @endif
        </div>
    </div>
    @empty
    <div class="col-12">
        <div class="glass p-5 text-center text-muted-glass">
            <i class="bi bi-shield-exclamation display-4 d-block mb-2 opacity-50"></i>
            {{ __('claim.empty') }}
        </div>
    </div>
    @endforelse
</div>

<div class="mt-3">{{ $claims->withQueryString()->links('vendor.pagination.bootstrap-5') }}</div>

@if($canCreateClaim)
<div class="modal fade" id="claimModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700">{{ __('claim.new') }}</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="claimForm" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">{{ __('claim.invoice') }}</label>
                        <select name="invoice_id" class="form-select select2" required data-placeholder="{{ __('claim.invoice') }}">
                            <option value=""></option>
                            @foreach($invoiceOptions as $invoice)
                            <option value="{{ $invoice['id'] }}" data-amount="{{ $invoice['amount'] }}">{{ $invoice['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('claim.type') }}</label>
                        <select name="claim_type" class="form-select" required>
                            @foreach(\App\Models\Claim::TYPES as $type)
                            <option value="{{ $type }}">{{ \App\Models\Claim::typeLabel($type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('claim.amount') }}</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('claim.notes') }}</label>
                        <textarea name="notes" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('claim.proof') }}</label>
                        <div class="drop-zone" id="claimDropZone">
                            <i class="bi bi-cloud-arrow-up display-6 text-muted-glass"></i>
                            <p class="text-muted-glass small mt-2 mb-0">Drag & drop atau klik untuk upload</p>
                            <p class="text-muted-glass" style="font-size:.7rem">{{ __('claim.proof_hint') }}</p>
                            <input type="file" name="proof_file" class="d-none" id="claimProofInput"
                                accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,video/*" required
                                data-compress-image="true" data-compress-profile="payment">
                        </div>
                        <div id="claimFilePreview" class="mt-2 d-none text-center"></div>
                    </div>
                    <button type="submit" class="btn btn-accent w-100 py-2" id="btnSubmitClaim">
                        <span class="spinner-border spinner-border-sm d-none me-1"></span>
                        <i class="bi bi-send me-1"></i>{{ __('claim.submit') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

@if($canRejectClaim)
<div class="modal fade" id="claimRejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700">{{ __('claim.reject') }}</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="claimRejectForm">
                    <input type="hidden" id="rejectClaimId">
                    <div class="mb-3">
                        <label class="form-label">{{ __('claim.review_notes') }}</label>
                        <textarea name="review_notes" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger-glow w-100" style="animation:none">{{ __('claim.reject') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

<div class="modal fade" id="claimProofPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700">{{ __('claim.preview_proof') }}</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img id="claimProofPreviewImage" src="" alt="{{ __('claim.proof_photo') }}" class="img-fluid rounded-3 w-100" style="max-height:75vh;object-fit:contain">
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.claim-review-note {
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid var(--glass-border);
    border-left: 3px solid var(--accent, #f59e0b);
    background: rgba(245, 158, 11, 0.06);
}
.claim-review-note__head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.claim-review-note__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: rgba(245, 158, 11, 0.14);
    color: #f59e0b;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.claim-review-note__title {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.claim-review-note__body {
    font-size: 0.875rem;
    line-height: 1.55;
    color: var(--text-primary);
    white-space: pre-wrap;
    word-break: break-word;
}
.claim-review-note__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 12px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed var(--glass-border);
    font-size: 0.75rem;
    color: var(--text-muted);
}
.claim-review-note__reviewer,
.claim-review-note__date {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.claim-review-note__reviewer strong {
    font-weight: 700;
    color: var(--text-primary);
}
[data-theme="light"] .claim-review-note {
    background: rgba(245, 158, 11, 0.08);
}
.claim-review-note--approved {
    border-left-color: #22c55e;
    background: rgba(34, 197, 94, 0.06);
}
.claim-review-note--approved .claim-review-note__icon {
    background: rgba(34, 197, 94, 0.14);
    color: #22c55e;
}
.claim-review-note--rejected {
    border-left-color: #ef4444;
    background: rgba(239, 68, 68, 0.06);
}
.claim-review-note--rejected .claim-review-note__icon {
    background: rgba(239, 68, 68, 0.14);
    color: #ef4444;
}
[data-theme="light"] .claim-review-note--approved {
    background: rgba(34, 197, 94, 0.08);
}
[data-theme="light"] .claim-review-note--rejected {
    background: rgba(239, 68, 68, 0.08);
}
.claim-proof-thumb-btn:hover img {
    opacity: 0.92;
}
.claim-proof-video {
    display: block;
}
</style>
@endpush

@push('scripts')
<script>
(function(){
    var form = document.querySelector('form[method="GET"]');
    if(!form) return;
    var timer;
    form.querySelectorAll('input[type="text"], input[type="search"]').forEach(function(el){
        el.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(function(){ form.submit(); }, 400); });
    });
    form.querySelectorAll('select:not(.select2-hidden-accessible)').forEach(function(el){
        el.addEventListener('change', function(){ form.submit(); });
    });
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof $ !== 'undefined') {
            $(form).find('.select2').on('change', function(){ form.submit(); });
        }
    });
})();

document.addEventListener('DOMContentLoaded', function() {
    const csrf = '{{ csrf_token() }}';
    const claimModalEl = document.getElementById('claimModal');
    const rejectModalEl = document.getElementById('claimRejectModal');
    const claimModal = claimModalEl ? bootstrap.Modal.getOrCreateInstance(claimModalEl) : null;
    const rejectModal = rejectModalEl ? bootstrap.Modal.getOrCreateInstance(rejectModalEl) : null;
    const dropZone = document.getElementById('claimDropZone');
    const proofInput = document.getElementById('claimProofInput');
    const proofPreviewModalEl = document.getElementById('claimProofPreviewModal');
    const proofPreviewModal = proofPreviewModalEl ? bootstrap.Modal.getOrCreateInstance(proofPreviewModalEl) : null;
    let claimUploadPreviewUrl = null;

    function revokeClaimUploadPreviewUrl() {
        if (claimUploadPreviewUrl) {
            URL.revokeObjectURL(claimUploadPreviewUrl);
            claimUploadPreviewUrl = null;
        }
    }

    $('#btnOpenClaimModal').on('click', function() {
        document.getElementById('claimForm')?.reset();
        revokeClaimUploadPreviewUrl();
        $('#claimFilePreview').addClass('d-none').empty();
        claimModal?.show();
    });

    $(document).on('click', '.claim-proof-thumb-btn', function() {
        const url = $(this).data('proof-url');
        if (!url) return;
        $('#claimProofPreviewImage').attr('src', url);
        proofPreviewModal?.show();
    });

    if (dropZone && proofInput) {
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
            revokeClaimUploadPreviewUrl();
            if (!e.target.files.length) {
                $('#claimFilePreview').addClass('d-none').empty();
                return;
            }
            const file = e.target.files[0];
            const preview = $('#claimFilePreview').removeClass('d-none');
            if (file.type && file.type.startsWith('video/')) {
                claimUploadPreviewUrl = URL.createObjectURL(file);
                preview.html(
                    '<video src="' + claimUploadPreviewUrl + '" controls preload="metadata" class="rounded-3" style="max-width:100%;max-height:220px;background:#000"></video>' +
                    '<div class="small text-muted-glass mt-1"><i class="bi bi-camera-video me-1"></i>' + file.name + '</div>'
                );
                return;
            }
            const reader = new FileReader();
            reader.onload = (ev) => preview.html(
                '<img src="' + ev.target.result + '" class="preview rounded-3" style="max-width:220px;max-height:220px">' +
                '<div class="small text-muted-glass mt-1"><i class="bi bi-image me-1"></i>' + file.name + '</div>'
            );
            reader.readAsDataURL(file);
        });
    }

    claimModalEl?.addEventListener('hidden.bs.modal', revokeClaimUploadPreviewUrl);

    $('#claimForm').on('submit', async function(e) {
        e.preventDefault();
        if (window.waitForImageCompression) {
            await window.waitForImageCompression();
        }
        const fd = new FormData(this);
        const $btn = $('#btnSubmitClaim');
        $btn.prop('disabled', true);
        $btn.find('.spinner-border').removeClass('d-none');
        $.ajax({
            url: '{{ route("claims.store") }}',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            headers: { 'X-CSRF-TOKEN': csrf },
            success: function(res) {
                showToast(res.message);
                claimModal?.hide();
                setTimeout(() => location.reload(), 700);
            },
            error: function(xhr) {
                const json = xhr.responseJSON;
                showToast((json && json.message) || (json && json.errors && Object.values(json.errors).flat().join('\n')) || 'Gagal', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');
            }
        });
    });

    $('.btn-approve-claim').on('click', function() {
        if (!confirm('Approve klaim ini?')) return;
        $.ajax({
            url: '{{ route("claims.approve", ["claim" => "__CLAIM_ID__"]) }}'.replace('__CLAIM_ID__', $(this).data('id')),
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: function(res) {
                showToast(res.message);
                setTimeout(() => location.reload(), 700);
            },
            error: function(xhr) {
                showToast(xhr.responseJSON?.message || 'Gagal', 'error');
            }
        });
    });

    $('.btn-reject-claim').on('click', function() {
        $('#rejectClaimId').val($(this).data('id'));
        rejectModal?.show();
    });

    $('#claimRejectForm').on('submit', function(e) {
        e.preventDefault();
        const id = $('#rejectClaimId').val();
        $.ajax({
            url: '{{ route("claims.reject", ["claim" => "__CLAIM_ID__"]) }}'.replace('__CLAIM_ID__', String(id)),
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            data: $(this).serialize(),
            success: function(res) {
                showToast(res.message);
                rejectModal?.hide();
                setTimeout(() => location.reload(), 700);
            },
            error: function(xhr) {
                showToast(xhr.responseJSON?.message || 'Gagal', 'error');
            }
        });
    });

    $(document).on('click', '.btn-delete-claim', function() {
        const id = $(this).data('id');
        const deleteUrl = '{{ route("claims.destroy", ["claim" => "__CLAIM_ID__"]) }}'.replace('__CLAIM_ID__', String(id));
        if (typeof confirmDelete === 'function') {
            confirmDelete(@json(__('claim.delete_confirm')), function() {
                $.ajax({
                    url: deleteUrl,
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrf },
                    success: function(res) {
                        showToast(res.message);
                        setTimeout(() => location.reload(), 700);
                    },
                    error: function(xhr) {
                        showToast(xhr.responseJSON?.message || 'Gagal', 'error');
                    }
                });
            });
            return;
        }
        if (!confirm(@json(__('claim.delete_confirm')))) return;
        $.ajax({
            url: deleteUrl,
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: function(res) {
                showToast(res.message);
                setTimeout(() => location.reload(), 700);
            },
            error: function(xhr) {
                showToast(xhr.responseJSON?.message || 'Gagal', 'error');
            }
        });
    });
});
</script>
@endpush
