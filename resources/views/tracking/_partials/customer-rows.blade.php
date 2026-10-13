@forelse($barangs as $b)
@php
    $publicTrackUrl = route('track.show', ['noIndex' => $b->no_index]);
    $barangInvoice = $b->invoiceItems->first()?->invoice;
    $invoicePdfUrl = $barangInvoice ? route('billing.invoice.pdf', $barangInvoice) : null;
@endphp
<tr class="customer-track-row" data-track-url="{{ $publicTrackUrl }}" role="link" tabindex="0" title="{{ __('tracking.tooltip_open_public_track') }}">
    <td class="fw-600" style="color:var(--text-primary)">{{ $b->no_index }}</td>
    <td class="small tracking-col-resi">@include('_partials.no-resi-stack', ['value' => $b->no_resi ?? null])</td>
    <td>{{ $b->marking ?? '-' }}</td>
    <td class="small">{{ $b->display_nama_barang ?? '-' }}</td>
    <td class="small" style="color:var(--text-muted)">{{ \App\Support\CustomerTrackingPresenter::tanggalMasukLabel($b) ?? '-' }}</td>
    <td>{{ $b->ctns }}</td>
    <td class="text-accent fw-700">{{ \App\Support\CbmFormat::format((float) $b->total_cbm) }}</td>
    <td class="small">{{ $b->containers->pluck('partai')->implode(', ') ?: '-' }}</td>
    <td class="small">{{ $b->destination ?? '-' }}</td>
    <td><span class="badge-glass {{ $b->status_badge }}">{{ \App\Support\CustomerTrackingPresenter::statusLabel((string) $b->status) }}</span></td>
    <td class="small" style="color:var(--text-muted)">{{ $b->display_tracking_location ?? '-' }}</td>
    <td class="tracking-col-notes">
        <span class="tracking-notes-text">{{ $b->display_tracking_description ?? '-' }}</span>
    </td>
    <td class="text-center">
        <div class="d-inline-flex gap-1 align-items-center justify-content-center">
            @if($invoicePdfUrl)
            <a href="{{ $invoicePdfUrl }}" class="btn btn-glass btn-sm customer-track-download" title="{{ __('tracking.tooltip_download_invoice') }}" onclick="event.stopPropagation();">
                <i class="bi bi-download"></i>
            </a>
            @endif
            @if($b->status === \App\Models\Barang::STATUS_BONGKAR && ! $b->rating)
            <a href="{{ $publicTrackUrl }}#publicTrackFeedback" target="_blank" rel="noopener noreferrer" class="btn btn-accent btn-sm" title="{{ __('dashboard.action_rate') }}" onclick="event.stopPropagation();">
                <i class="bi bi-star"></i>
            </a>
            @endif
            <a href="{{ $publicTrackUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-glass btn-sm customer-track-open" title="{{ __('tracking.tooltip_open_public_track') }}" onclick="event.stopPropagation();">
                <i class="bi bi-link-45deg"></i>
            </a>
        </div>
    </td>
</tr>
@empty
<tr><td colspan="13" class="text-center py-4" style="color:var(--text-muted)">{{ __('tracking.no_items') }}</td></tr>
@endforelse
