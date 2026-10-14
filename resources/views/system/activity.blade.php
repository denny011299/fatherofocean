@extends('layouts.app')
@section('title', __('system.activity_title'))

@section('content')
<div class="glass p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label">{{ __('system.filter_action') }}</label>
            <input type="text" name="action" class="form-control" placeholder="{{ __('system.filter_action_placeholder') }}" value="{{ request('action') }}">
        </div>
        <div class="col-md-2"><button class="btn btn-accent w-100">{{ __('system.filter') }}</button></div>
        <div class="col-md-2"><a href="{{ route('activity.index') }}" class="btn btn-glass w-100">{{ __('system.reset') }}</a></div>
    </form>
</div>

<div class="glass p-4">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <div class="stat-icon-squircle squircle-indigo" style="width:38px;height:38px;font-size:1.1rem;border-radius:12px;">
                <i class="bi bi-clock-history"></i>
            </div>
            <div>
                <h5 class="fw-700 mb-0" style="color:var(--text-primary)">{{ __('system.activity_title') }}</h5>
                <div style="font-size:0.75rem;color:var(--text-muted)">Rekam jejak seluruh aktivitas perubahan data pengguna</div>
            </div>
        </div>
        @if($logs->total() > 0)
        <span class="text-muted-glass small">
            {{ __('system.showing', [
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'total' => $logs->total(),
            ]) }}
        </span>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table-glass">
            <thead>
                <tr>
                    <th>{{ __('system.col_time') }}</th>
                    <th>{{ __('system.col_user') }}</th>
                    <th>{{ __('system.col_action') }}</th>
                    <th>{{ __('system.col_model') }}</th>
                    <th>{{ __('system.col_detail') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                @php($summary = $log->detailSummary())
                <tr>
                    <td class="text-muted-glass small">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                    <td class="text-white fw-600">{{ $log->user?->name ?? 'System' }}</td>
                    <td><span class="badge-glass">{{ $log->action }}</span></td>
                    <td class="text-muted-glass small">
                        {{ $log->model_type ? class_basename($log->model_type) : '-' }}
                        {{ $log->model_id ? '#' . $log->model_id : '' }}
                    </td>
                    <td class="small">
                        @if($summary !== '')
                        <div class="text-white text-break mb-1">{{ $summary }}</div>
                        @if($log->hasDetail())
                        <button type="button"
                                class="btn btn-glass btn-sm btn-activity-detail"
                                data-bs-toggle="modal"
                                data-bs-target="#activityDetailModal"
                                data-action="{{ $log->action }}"
                                data-model="{{ $log->model_type ? class_basename($log->model_type) . ($log->model_id ? ' #' . $log->model_id : '') : '-' }}"
                                data-time="{{ $log->created_at->format('d/m/Y H:i:s') }}"
                                data-user="{{ $log->user?->name ?? 'System' }}"
                                data-summary="{{ $summary }}"
                                data-old='@json($log->old_values)'
                                data-new='@json($log->new_values)'>
                            <i class="bi bi-eye"></i> {{ __('system.view_detail') }}
                        </button>
                        @endif
                        @else
                        <span class="text-muted-glass">-</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="text-center text-muted-glass py-4">{{ __('system.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $logs->withQueryString()->links('vendor.pagination.bootstrap-5') }}</div>
</div>

<div class="modal fade" id="activityDetailModal" tabindex="-1" aria-labelledby="activityDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700" id="activityDetailModalLabel">
                    <i class="bi bi-clock-history me-1 text-accent"></i>{{ __('system.activity_detail') }}
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('system.close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="glass p-3 mb-3" style="border-radius:1rem">
                    <div class="row g-2 small">
                        <div class="col-md-6">
                            <span class="text-muted-glass d-block">{{ __('system.label_time') }}</span>
                            <span class="fw-600" id="activityDetailTime">-</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted-glass d-block">{{ __('system.label_user') }}</span>
                            <span class="fw-600" id="activityDetailUser">-</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted-glass d-block">{{ __('system.label_action') }}</span>
                            <span class="badge-glass" id="activityDetailAction">-</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted-glass d-block">{{ __('system.label_model') }}</span>
                            <span class="fw-600" id="activityDetailModel">-</span>
                        </div>
                    </div>
                </div>
                <p class="small text-white text-break mb-3" id="activityDetailSummary"></p>
                <div id="activityDetailValues"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('system.close') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var $modal = $('#activityDetailModal');
    var noDetailText = @json(__('system.no_detail_data'));
    var colField = @json(__('system.modal_field'));
    var colBefore = @json(__('system.modal_before'));
    var colAfter = @json(__('system.modal_after'));

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    function formatValue(value) {
        if (value === null || value === undefined) {
            return '<span class="text-muted-glass">-</span>';
        }

        if (typeof value === 'object') {
            return '<pre class="mb-0 small text-break" style="white-space:pre-wrap">' + escapeHtml(JSON.stringify(value, null, 2)) + '</pre>';
        }

        return '<span class="text-break">' + escapeHtml(value) + '</span>';
    }

    function renderActivityDetail(oldValues, newValues) {
        var oldData = oldValues && typeof oldValues === 'object' ? oldValues : {};
        var newData = newValues && typeof newValues === 'object' ? newValues : {};
        var keys = Object.keys(oldData).concat(Object.keys(newData)).filter(function(key, index, list) {
            return list.indexOf(key) === index && key !== 'event';
        });

        if (!keys.length) {
            return '<p class="text-muted-glass mb-0">' + escapeHtml(noDetailText) + '</p>';
        }

        var rows = keys.map(function(key) {
            return '<tr>'
                + '<td class="text-muted-glass fw-600">' + escapeHtml(key) + '</td>'
                + '<td>' + formatValue(oldData[key]) + '</td>'
                + '<td>' + formatValue(newData[key]) + '</td>'
                + '</tr>';
        }).join('');

        return '<div class="table-responsive">'
            + '<table class="table-glass mb-0">'
            + '<thead><tr>'
            + '<th>' + escapeHtml(colField) + '</th>'
            + '<th>' + escapeHtml(colBefore) + '</th>'
            + '<th>' + escapeHtml(colAfter) + '</th>'
            + '</tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table>'
            + '</div>';
    }

    function parseJsonAttribute(value) {
        if (!value) {
            return null;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }

    $(document).on('click', '.btn-activity-detail', function() {
        var $button = $(this);

        $('#activityDetailTime').text($button.data('time') || '-');
        $('#activityDetailUser').text($button.data('user') || '-');
        $('#activityDetailAction').text($button.data('action') || '-');
        $('#activityDetailModel').text($button.data('model') || '-');
        $('#activityDetailSummary').text($button.data('summary') || '');
        $('#activityDetailValues').html(renderActivityDetail(
            parseJsonAttribute($button.attr('data-old')),
            parseJsonAttribute($button.attr('data-new'))
        ));
    });

    $modal.on('hidden.bs.modal', function() {
        $('#activityDetailValues').empty();
        $('#activityDetailSummary').empty();
    });
});
</script>
@endpush
