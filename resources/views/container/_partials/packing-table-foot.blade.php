<tfoot id="packingTableFoot">
    <tr style="border-top:2px solid var(--glass-border)">
        <td class="fw-700" style="color:var(--text-primary)">{{ __('container.total_row') }}</td>
        <td></td>
        <td></td>
        <td>
            <div class="packing-coly-volume-stack">
                <div>
                    <span class="fw-700" id="packingFootColy" style="color:var(--text-primary)">{{ $container->barangs->sum('ctns') }}</span>
                    <span class="text-muted">{{ __('barang.unit_coly') }}</span>
                </div>
                <div class="packing-coly-volume-cbm">
                    <span class="fw-800" id="packingFootVolume">{{ \App\Support\CbmFormat::format($preciseUsedVolume) }}</span>
                    <span class="text-muted">{{ __('barang.unit_cbm') }}</span>
                </div>
            </div>
        </td>
        <td colspan="6"></td>
    </tr>
</tfoot>
