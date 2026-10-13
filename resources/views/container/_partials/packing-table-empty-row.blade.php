<tr class="packing-empty-row">
    <td colspan="10" class="text-center py-4 packing-empty-cell">
        <i class="bi bi-inbox" style="font-size:2rem;opacity:.4;color:var(--text-dim)"></i>
        <div class="mt-2" style="color:var(--text-muted)">{{ __('container.empty_container_items') }}</div>
        @canany(['load barang', 'unload barang'])
        <div class="mt-3 d-flex flex-wrap gap-2 justify-content-center align-items-center packing-empty-actions">
            <a href="{{ route('container.load.page', $container) }}" class="btn btn-accent btn-sm">
                <i class="bi bi-box-arrow-in-down me-1"></i>{{ __('container.load_items') }}
            </a>
            <button type="button" class="btn btn-glass btn-sm btn-add-packaging">
                <i class="bi bi-upc-scan me-1"></i>{{ __('container.add_via_index') }}
            </button>
        </div>
        @endcanany
    </td>
</tr>
