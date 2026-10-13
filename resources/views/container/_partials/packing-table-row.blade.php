@php
    $markingLabel = $b->marking ? $b->marking : __('container.no_marking');
    $barangInvoice = $b->invoiceItems->first()?->invoice;
    $invoiceUrl = $barangInvoice
        ? route('export.invoice.pdf', $barangInvoice)
        : route('export.container.marking-invoice.pdf', ['container' => $container, 'marking' => $b->marking ?? '']);
    $displayCbm = $b->displayTotalCbm();
@endphp
<tr class="packing-data-row">
    <td>
        <div class="fw-600" style="color:var(--text-primary)">{{ $b->no_index }}</div>
        @if($b->no_po)
        <div style="font-size:.7rem;color:var(--text-muted)">{{ __('container.po_prefix') }} {{ $b->no_po }}</div>
        @endif
    </td>

    <td class="col-mark">
        <div class="mark-cell">
            <span class="badge-glass marking-badge">{{ $markingLabel }}</span>
            @if($b->customer_name ?? $b->customer?->name)
            <div class="mark-meta">{{ $b->customer_name ?? $b->customer?->name }}</div>
            @endif
            @if($b->destination)
            <div class="mark-meta">{{ $b->destination }}</div>
            @endif
        </div>
    </td>

    <td>
        @if($container->partai)
        <span class="small fw-600" style="color:var(--text-primary)">{{ $container->partai }}</span>
        @else
        <span class="small" style="color:var(--text-dim)">-</span>
        @endif
    </td>

    <td>
        <div class="packing-coly-volume-stack">
            <div>
                <span class="fw-600">{{ $b->ctns }}</span>
                <span class="text-muted">{{ __('barang.unit_coly') }}</span>
            </div>
            <div class="packing-coly-volume-cbm">
                {{ \App\Support\CbmFormat::format($displayCbm) }}
                <span class="text-muted">{{ __('barang.unit_cbm') }}</span>
            </div>
        </div>
    </td>

    <td>
        @if($b->hasNoResi())
        <span class="small" style="color:var(--text-primary)">{{ $b->displayNoResi() }}</span>
        @else
        <span class="small" style="color:var(--text-dim)">-</span>
        @endif
    </td>

    <td>
        <div class="small" style="color:var(--text-primary)">{{ $b->itemsSummary() }}</div>
    </td>

    <td>
        @if($b->price_per_cbm > 0)
        <div class="fw-600 small" style="color:var(--text-primary)">
            {{ number_format($b->price_per_cbm, 0, ',', '.') }}{{ __('container.per_cbm') }}
        </div>
        @if($b->extra_charge > 0)
        <div style="font-size:.7rem;color:var(--text-muted)">
            +{{ number_format($b->extra_charge, 0, ',', '.') }} {{ __('container.extra_suffix') }}
        </div>
        @endif
        @php
            $packingNominal = \App\Support\InvoicePdfData::barangChargeBeforeDiscountIdr(
                $b,
                $b->invoiceItems->first(),
            );
        @endphp
        <div class="fw-700 small text-accent">
            Rp {{ number_format($packingNominal, 0, ',', '.') }}
        </div>
        @else
        <span class="small" style="color:var(--text-dim)">{{ __('container.price_not_set') }}</span>
        @endif
    </td>

    <td>
        @if($b->keterangan)
        <span class="small" style="color:var(--text-primary)">{{ Str::limit($b->keterangan, 40) }}</span>
        @else
        <span class="small" style="color:var(--text-dim)">-</span>
        @endif
    </td>

    <td class="small" style="color:var(--text-muted)">
        {{ $b->pivot->loaded_at ? \Carbon\Carbon::parse($b->pivot->loaded_at)->format('d M Y') : '-' }}
    </td>

    <td class="col-actions">
        <div class="d-flex packing-row-actions">
            @canany(['view invoice', 'export invoice'])
            <a href="{{ $invoiceUrl }}"
               class="btn btn-glass btn-sm btn-invoice-pdf"
               title="{{ __('container.invoice_pdf_tooltip') }}">
                <i class="bi bi-cash-stack me-1" style="color:#16a34a"></i>{{ __('container.invoice') }}
            </a>
            @endcanany
            @canany(['submit repricing', 'change price'])
            <button type="button" class="btn btn-sm btn-reprice"
                    data-id="{{ $b->id }}"
                    data-index="{{ $b->no_index }}"
                    data-marking="{{ $b->marking }}"
                    data-cbm="{{ $displayCbm }}"
                    data-gw="{{ $b->total_gross_weight }}"
                    data-threshold="{{ $b->weight_threshold_kg }}"
                    data-price="{{ $b->price_per_cbm }}"
                    data-extra="{{ $b->extra_charge }}"
                    data-weight-price="{{ $b->weight_price }}"
                    title="{{ __('container.change_price') }}">
                <i class="bi bi-tags me-1"></i>{{ __('container.change_price') }}
            </button>
            @endcanany
            @if($canUnloadShipment)
            <button
                type="button"
                class="btn btn-sm btn-delete-barang btn-unload-barang"
                data-id="{{ $b->id }}"
                data-index="{{ $b->no_index }}"
                data-marking="{{ $markingLabel }}"
                data-cbm="{{ $displayCbm }}"
                data-ctns="{{ $b->ctns }}"
                @if($unloadLockedByDeparture) disabled @endif
                title="{{ $unloadLockedByDeparture ? __('container.tooltip_unload_departed') : __('container.unload_from_container') }}"
            >
                <i class="bi bi-trash me-1"></i>{{ __('container.delete_from_container') }}
            </button>
            @endif
        </div>
    </td>
</tr>
