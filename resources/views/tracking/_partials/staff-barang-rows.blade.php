@forelse($barangs as $b)
@php
    $publicTrackUrl = route('track.show', ['noIndex' => $b->no_index]);
@endphp
<tr class="staff-track-row" data-track-url="{{ $publicTrackUrl }}" role="link" tabindex="0" title="{{ __('tracking.tooltip_open_public_track') }}">
    <td class="fw-600" style="color:var(--text-primary)">{{ $b->no_index }}</td>
    <td>{{ $b->marking ?? '-' }}</td>
    <td class="small">{{ $b->display_nama_barang ?? '-' }}</td>
    <td class="small">{{ $b->marketing_name ?? '-' }}</td>
    <td>{{ $b->ctns }}</td>
    <td class="text-accent fw-700">{{ \App\Support\CbmFormat::format((float) $b->total_cbm) }}</td>
    <td class="small">{{ $b->containers->pluck('partai')->filter()->unique()->implode(', ') ?: '-' }}</td>
    <td><span class="badge-glass {{ $b->status_badge }}">{{ \App\Models\Barang::shipmentStatusLabel($b->status) }}</span></td>
    <td class="small" style="color:var(--text-muted)">{{ $b->display_tracking_location ?? '-' }}</td>
    <td class="tracking-col-notes">
        <span class="tracking-notes-text">{{ $b->display_tracking_description ?? '-' }}</span>
    </td>
    <td class="text-center">
        <a href="{{ $publicTrackUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-glass btn-sm staff-track-open" title="{{ __('tracking.tooltip_open_public_track') }}" onclick="event.stopPropagation();">
            <i class="bi bi-link-45deg"></i>
        </a>
    </td>
</tr>
@empty
<tr><td colspan="11" class="text-center py-4" style="color:var(--text-muted)">{{ __('tracking.no_items') }}</td></tr>
@endforelse
