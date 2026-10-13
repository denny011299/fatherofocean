@forelse($containers as $c)
@php
    $markingsFull = trim((string) ($c->markings_list ?? ''));
    $namaFull = trim((string) ($c->display_nama_barang ?? ''));
    if ($namaFull === '') {
        $namaFull = '-';
    }
    $markingsShort = $markingsFull !== '' ? \Illuminate\Support\Str::limit($markingsFull, 18) : '-';
    $namaShort = \Illuminate\Support\Str::limit($namaFull, 24);
    $indexesShort = \Illuminate\Support\Str::limit((string) ($c->no_indexes_list ?? ''), 28);
    $needsExpand = ($markingsFull !== '' && $markingsShort !== $markingsFull)
        || ($namaShort !== $namaFull);
@endphp
<tr class="tracking-container-row{{ $needsExpand ? ' has-expandable' : '' }}"@if($needsExpand) data-expandable="1" aria-expanded="false"@endif>
    <td class="fw-600" style="color:var(--text-primary)">
        <div>{{ $c->container_label }}</div>
        <div class="small" style="color:var(--text-muted)">{{ $indexesShort }}</div>
    </td>
    <td class="col-mark-customer{{ $needsExpand ? ' tracking-expand-hit' : '' }}">
        <div class="mark-cell">
            <div class="tracking-expand-head">
                <div
                    class="mark-code tracking-expand-text{{ $needsExpand ? ' is-collapsed' : '' }}"
                    data-short="{{ e($markingsShort) }}"
                    data-full="{{ e($markingsFull !== '' ? $markingsFull : '-') }}"
                >{{ $markingsShort }}</div>
                @if($needsExpand)
                <span class="tracking-expand-cue" title="{{ __('tracking.expand_details') }}" aria-hidden="true">
                    <i class="bi bi-chevron-down"></i>
                </span>
                @endif
            </div>
            <div class="mark-meta">{{ $c->customers_list }}</div>
        </div>
    </td>
    <td>{{ $c->shipments_count }}</td>
    <td>
        {{ $c->total_ctns_display }} / <span class="text-accent fw-700">{{ \App\Support\CbmFormat::format((float) $c->total_cbm_display) }}</span>
    </td>
    <td class="small tracking-nama-barang-cell{{ $needsExpand ? ' tracking-expand-hit' : '' }}" style="color:var(--text-muted)">
        <div
            class="tracking-expand-text{{ $needsExpand ? ' is-collapsed' : '' }}"
            data-short="{{ e($namaShort) }}"
            data-full="{{ e($namaFull) }}"
        >{{ $namaShort }}</div>
    </td>
    <td><span class="badge-glass {{ $c->display_tracking_badge }}">{{ $c->display_tracking_status_label }}</span></td>
    <td class="small" style="color:var(--text-muted)">{{ $c->display_tracking_location ?? '-' }}</td>
    <td class="tracking-col-notes">
        <span class="tracking-notes-text">{{ $c->display_tracking_description ?? '-' }}</span>
    </td>
    <td class="tracking-col-actions">
        <div class="d-flex gap-1 tracking-action-group">
            <button class="btn btn-accent btn-sm tracking-action-btn btn-timeline" data-id="{{ $c->id }}" data-label="{{ $c->container_label }}" title="{{ __('tracking.tooltip_timeline') }}">
                <i class="bi bi-geo-alt"></i>
            </button>
            @can('update tracking status')
            <button class="btn btn-glass btn-sm tracking-action-btn btn-update-status" data-id="{{ $c->id }}" data-label="{{ $c->container_label }}" title="{{ __('tracking.tooltip_update_status') }}">
                <i class="bi bi-pencil-square"></i>
            </button>
            @endcan
            @canany(['view delivery trip', 'create delivery trip', 'manage delivery trip'])
            <button
                type="button"
                class="btn btn-glass btn-sm tracking-action-btn btn-surat-jalan"
                data-id="{{ $c->id }}"
                data-label="{{ $c->container_label }}"
                data-has-trip="{{ $c->has_delivery_trip ? '1' : '0' }}"
                title="{{ $c->has_delivery_trip ? __('tracking.tooltip_view_surat_jalan') : __('tracking.tooltip_create_surat_jalan') }}"
            >
                <i class="bi {{ $c->has_delivery_trip ? 'bi-file-earmark-text' : 'bi-file-earmark-plus' }}"></i>
            </button>
            @endcanany
            <a
                href="{{ route('container.detail', $c) }}"
                class="btn btn-glass btn-sm tracking-action-btn"
                title="{{ __('common.tooltip_detail') }}"
            >
                <i class="bi bi-eye"></i>
            </a>
        </div>
    </td>
</tr>
@empty
<tr><td colspan="10" class="text-center py-4" style="color:var(--text-muted)">{{ __('tracking.no_tracking') }}</td></tr>
@endforelse
