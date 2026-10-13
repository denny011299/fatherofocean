{{-- Timeline Modal (iOS-style) --}}
<div class="modal fade" id="timelineModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-geo-alt-fill me-1 text-accent"></i>Timeline Container — <span id="tlTitle" class="text-accent"></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                {{-- Container summary --}}
                <div class="glass-solid p-3 mb-3" id="tlSummary"></div>
                <div id="timelineContent">
                    <div class="text-center py-4 text-muted-glass">Memuat...</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Update Status Modal --}}
@can('update tracking status')
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-signpost-2-fill me-1 text-warning"></i>Update Status Container: <span id="usTitle" class="text-accent"></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="updateStatusForm">
                    <input type="hidden" id="usContainerId">
                    <div class="mb-3">
                        <label class="form-label">Status Baru</label>
                        <select name="status" class="form-select" required>
                            @foreach(\App\Models\Container::TRACKING_STATUSES as $status)
                            <option value="{{ $status }}">{{ \App\Models\Container::trackingStatusLabel($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lokasi</label>
                        <input type="text" name="location" class="form-control" placeholder="Guangzhou, Shanghai Port, Jakarta...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Keterangan..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-accent w-100 py-2">
                        <i class="bi bi-check-lg me-1"></i>Update Tracking Container
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Edit Timeline Entry Modal --}}
<div class="modal fade" id="editTimelineModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-pencil me-1 text-accent"></i>Edit Timeline — <span id="etTitle" class="text-accent"></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editTimelineForm">
                    <input type="hidden" id="etContainerId">
                    <input type="hidden" id="etEntryId">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="etStatus" class="form-select" required>
                            @foreach(\App\Models\Container::TRACKING_STATUSES as $status)
                            <option value="{{ $status }}">{{ \App\Models\Container::trackingStatusLabel($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lokasi</label>
                        <input type="text" name="location" id="etLocation" class="form-control" placeholder="Guangzhou, Shanghai Port, Jakarta...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="description" id="etDescription" class="form-control" rows="2" placeholder="Keterangan..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-accent w-100 py-2">
                        <i class="bi bi-check-lg me-1"></i>Simpan Perubahan
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endcan

<div class="modal fade" id="deliveryTripModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title fw-700 mb-1">
                        <i class="bi bi-file-earmark-text me-1 text-accent"></i>Surat Jalan
                        <span id="sjTitle" class="text-accent"></span>
                    </h6>
                    <div class="small text-muted-glass" id="sjSubtitle">Memuat data...</div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="deliveryTripModalBody">
                @include('tracking._partials.surat-jalan-workspace')
            </div>
        </div>
    </div>
</div>


{{-- Rating Modal --}}
@can('rate delivery')
<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-star-fill me-1 text-warning"></i>Beri Rating</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="rateForm">
                    <input type="hidden" id="rateBarangId">
                    <div class="text-center mb-3">
                        <div class="star-rating">
                            @for($i = 5; $i >= 1; $i--)
                            <input type="radio" name="score" value="{{ $i }}" id="rs{{ $i }}">
                            <label for="rs{{ $i }}"><i class="bi bi-star-fill"></i></label>
                            @endfor
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Review</label>
                        <textarea name="review" class="form-control" rows="3" placeholder="Bagikan pengalaman..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-accent w-100">Kirim Rating</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endcan
