<input type="hidden" name="user_id" id="barangUserId" value="{{ old('user_id', optional($barang)->user_id ?? '') }}">
<div id="barangFormErrors" class="alert alert-danger d-none mb-3" role="alert">
    <div class="fw-600 mb-1">{{ __('common.check_fields') }}</div>
    <ul id="barangFormErrorsList" class="mb-0 ps-3"></ul>
</div>

<div class="row g-3 mb-3">
    <x-form-group :label="__('barang.label_warehouse')" cols="col-md-4" :required="true">
        <select name="gudang_china_id" class="form-select select2" required data-placeholder="{{ __('barang.placeholder_warehouse') }}">
            <option value="">{{ __('barang.placeholder_warehouse') }}</option>
            @foreach($gudangList as $gudang)
            <option value="{{ $gudang->id }}" @selected(old('gudang_china_id', optional($barang)->gudang_china_id ?? '') == $gudang->id)>{{ $gudang->name }}{{ $gudang->address ? ' — ' . $gudang->address : '' }}</option>
            @endforeach
        </select>
        <div class="invalid-feedback"></div>
    </x-form-group>
    <x-form-group :label="__('barang.label_date')" cols="col-md-4">
        <input type="date" name="tanggal_masuk" class="form-control" value="{{ old('tanggal_masuk', isset($barang) && optional($barang)->tanggal_masuk ? optional($barang)->tanggal_masuk->format('Y-m-d') : date('Y-m-d')) }}">
        <div class="invalid-feedback"></div>
    </x-form-group>
    <x-form-group :label="__('barang.label_no_index')" cols="col-md-4" :required="true">
        <input type="text" name="no_index" class="form-control" placeholder="{{ __('barang.placeholder_no_index') }}" required
            value="{{ old('no_index', optional($barang)->no_index ?? '') }}">
        <div class="invalid-feedback"></div>
    </x-form-group>
</div>

<div class="row g-3 mb-3">
    <x-form-group :label="__('barang.label_supplier')" cols="col-12 col-md-3">
        <input type="text" name="supplier_chinese" class="form-control" placeholder="{{ __('barang.placeholder_supplier') }}"
            value="{{ old('supplier_chinese', optional($barang)->supplier_chinese ?? '') }}">
    </x-form-group>
    <x-form-group :label="__('barang.label_marking')" cols="col-12 col-md-5" :required="true">
        <select name="marking" class="form-select select2" data-placeholder="{{ __('barang.placeholder_marking') }}" id="markingSelect" required>
            <option value=""></option>
            @foreach($markings as $mk)
            <option value="{{ $mk->displayCode() }}" data-marking-id="{{ $mk->id }}" data-customer-id="{{ $mk->user_id }}" data-customer-name="{{ $mk->customer_name }}"
                @selected(old('marking', optional($barang)->marking ?? '') === $mk->displayCode())>{{ $mk->dropdownLabel() }}</option>
            @endforeach
        </select>
        <div class="invalid-feedback"></div>
    </x-form-group>
    <x-form-group :label="__('barang.label_customer')" cols="col-12 col-md-4" :required="true">
        <input type="text" name="customer_name" id="barangCustomerName" class="form-control" placeholder="{{ __('barang.placeholder_customer') }}" required
            value="{{ old('customer_name', optional($barang)->customer_name ?? '') }}">
        <div class="invalid-feedback"></div>
    </x-form-group>
</div>

<div class="row g-3 mb-3">
    {{-- Temporarily hidden: remove d-none from cols to show again --}}
    <x-form-group :label="__('barang.col_coly')" cols="col-12 col-md-3 d-none">
        <input type="number" name="ctns" class="form-control" min="0" value="{{ old('ctns', optional($barang)->ctns ?? 0) }}">
    </x-form-group>
    <x-form-group :label="__('barang.label_total_invoice')" cols="col-12 col-md-4 d-none">
        <input type="text" name="price" class="form-control mask-currency-rmb" value="{{ old('price', optional($barang)->price ?? 0) }}">
    </x-form-group>
    <x-form-group :label="__('barang.label_packing_list_invoice')" cols="col-12 col-md-5">
        <input type="file" name="invoice_file" id="invoiceFileInput" class="form-control"
            accept="{{ \App\Support\BarangOriginalPackingList::acceptAttribute() }}">
        <div id="invoicePreview" class="mt-2 d-none">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div id="invoiceFileBadge">
                    <span class="badge-glass d-inline-flex align-items-center gap-1" style="padding:4px 10px">
                        <i class="bi" id="invoiceFileIcon"></i>
                        <span id="invoiceFileName" style="font-size:.75rem"></span>
                    </span>
                </div>
                <button type="button" class="btn btn-sm text-danger" id="btnRemoveInvoice" title="{{ __('common.remove_file') }}"><i class="bi bi-x-circle"></i></button>
            </div>
        </div>
    </x-form-group>
</div>

<div class="row g-3 mb-3 align-items-stretch barang-pricing-row">
    <div class="col-12 col-lg-8">
        <div class="row g-3">
            <x-form-group :label="__('barang.label_item_type')" cols="col-12 col-md-4">
                <select name="cbm_type" id="cbmTypeSelect" class="form-select select2" data-placeholder="{{ __('barang.placeholder_item_type') }}">
                    @php
                        $savedItemType = trim((string) old('cbm_type', optional($barang)->cbm_type ?? ''));
                        $savedItemLabel = $savedItemType;
                        if ($savedItemType !== '' && ctype_digit($savedItemType)) {
                            $savedItemLabel = \App\Models\MasterPricingItem::query()
                                ->whereKey((int) $savedItemType)
                                ->value('name') ?? $savedItemType;
                        }
                    @endphp
                    @if($savedItemType !== '')
                    <option value="{{ $savedItemType }}" selected>{{ $savedItemLabel }}</option>
                    @endif
                </select>
            </x-form-group>
            <x-form-group :label="__('barang.label_rate')" cols="col-12 col-md-4">
                @php
                    $globalRate = \App\Support\DailyRate::rate();
                    $displayRate = old('rate', optional($barang)->rate ?? $globalRate ?? 0);
                @endphp
                <input type="text"
                    name="rate"
                    class="form-control mask-currency barang-daily-rate-field"
                    value="{{ $displayRate }}"
                    readonly
                    autocomplete="off"
                    tabindex="-1">
            </x-form-group>
            <x-form-group :label="__('barang.label_price_cbm')" cols="col-12 col-md-4">
                <input type="text"
                    name="price_per_cbm"
                    id="pricePerCbmInput"
                    class="form-control mask-currency barang-marking-price-field"
                    value="{{ old('price_per_cbm', optional($barang)->price_per_cbm ?? 0) }}"
                    readonly
                    autocomplete="off">
            </x-form-group>
            <x-form-group :label="__('barang.label_marking_item_name')" cols="col-12">
                <div id="markingItemNameDisplay" class="barang-marking-item-name" hidden>
                    <div class="barang-marking-item-name__value" data-item-name></div>
                </div>
                <div id="markingItemNameEmpty" class="form-text mb-0">{{ __('barang.placeholder_marking_item_name') }}</div>
            </x-form-group>
        </div>
        <div class="row g-3 mt-0 mt-md-3">
            <x-form-group :label="__('barang.label_weight_price')" cols="col-12 col-md-6">
                <input type="text"
                    name="weight_price"
                    id="weightPriceInput"
                    class="form-control mask-currency barang-marking-price-field"
                    value="{{ old('weight_price', optional($barang)->weight_price ?? 0) }}"
                    readonly
                    autocomplete="off">
            </x-form-group>
            <x-form-group :label="__('barang.label_weight_threshold_kg')" cols="col-12 col-md-6">
                <input type="number" name="weight_threshold_kg" class="form-control mask-number" step="1" min="0" value="{{ old('weight_threshold_kg', optional($barang)->weight_threshold_kg ?? 0) }}">
            </x-form-group>
        </div>
        @if($canViewHargaModal ?? false)
        <div class="row g-3 mt-0 mt-md-3 barang-harga-modal-section">
            <div class="col-12">
                <div class="small fw-600 text-warning"><i class="bi bi-lock-fill me-1"></i>{{ __('finance.cost_price_section') }}</div>
            </div>
            <x-form-group :label="__('finance.cost_price_per_cbm')" cols="col-12 col-md-4">
                <input type="text"
                    name="cost_price_per_cbm"
                    id="costPricePerCbmInput"
                    class="form-control mask-currency"
                    value="{{ old('cost_price_per_cbm', optional($barang)->cost_price_per_cbm ?? '') }}"
                    @unless($canEditHargaModal ?? false) readonly tabindex="-1" @endunless
                    autocomplete="off">
            </x-form-group>
            <x-form-group :label="__('finance.cost_weight_price')" cols="col-12 col-md-4">
                <input type="text"
                    name="cost_weight_price"
                    id="costWeightPriceInput"
                    class="form-control mask-currency"
                    value="{{ old('cost_weight_price', optional($barang)->cost_weight_price ?? '') }}"
                    @unless($canEditHargaModal ?? false) readonly tabindex="-1" @endunless
                    autocomplete="off">
            </x-form-group>
            @if(isset($barang) && $barang->company_loyalty_amount)
            <x-form-group :label="__('barang.label_company_loyalty')" cols="col-12 col-md-4">
                <input type="text"
                    class="form-control mask-currency"
                    value="{{ number_format((float) $barang->company_loyalty_amount, 0, ',', '.') }}"
                    readonly
                    tabindex="-1"
                    autocomplete="off">
                <div class="form-text">{{ __('barang.company_loyalty_hint') }}</div>
            </x-form-group>
            @endif
        </div>
        @endif
    </div>
    <div class="col-12 col-lg-4 barang-pricing-calc-wrap">
        <div class="glass p-3 h-100 d-flex flex-column" id="pricingCalcBox">
            <div class="fw-600 mb-2" style="color:var(--text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.1em">
                <i class="bi bi-calculator me-1"></i>{{ __('barang.cost_estimate') }}
            </div>
            <div id="calcMethodLine" class="d-none mb-1" style="color:var(--text-muted);font-size:.72rem"></div>
            <div id="calcCbmLine" style="color:var(--text-secondary);font-size:.8rem">CBM: 0 &times; Rp 0 = <span class="text-accent fw-700">Rp 0</span></div>
            <div id="calcWeightLine" class="d-none" style="color:var(--text-secondary);font-size:.8rem">Berat: 0 kg &times; Rp 0 = <span class="text-accent fw-700">Rp 0</span></div>
            <hr class="my-1 mt-auto" style="border-color:var(--glass-border)">
            <div id="calcTotalLine" class="fw-700" style="color:var(--text-primary);font-size:.8rem">Total Estimasi: <span class="text-accent">Rp 0</span></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <x-form-group :label="__('barang.label_packing')" cols="col-md-4">
        <select name="packing_id" class="form-select select2" data-placeholder="{{ __('barang.placeholder_packing') }}" id="packingSelect">
            <option value="">{{ __('barang.placeholder_packing') }}</option>
            @foreach($packings as $pk)
            <option value="{{ $pk->id }}" @selected(old('packing_id', optional($barang)->packing_id ?? '') == $pk->id)>{{ $pk->name }}</option>
            @endforeach
        </select>
    </x-form-group>
    <x-form-group :label="__('barang.label_other')" cols="col-md-4">
        <select name="other" class="form-select select2" data-placeholder="{{ __('barang.label_other') }}">
            <option value="">{{ __('barang.placeholder_other') }}</option>
            @foreach($classifications as $cl)
            <option value="{{ Str::lower($cl->name) }}" @selected(old('other', optional($barang)->other ?? '') === Str::lower($cl->name))>{{ $cl->name }}</option>
            @endforeach
        </select>
    </x-form-group>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        @php
            $goodReceiptViewUrl = isset($barang)
                ? \App\Support\PublicStorageUrl::url($barang->good_receipt_path)
                : null;
            $goodReceiptExists = $goodReceiptViewUrl !== null;
        @endphp
        <div class="good-receipt-field">
            <label class="form-label" for="goodReceiptBox">{{ __('barang.label_good_receipt') }}</label>
            <div class="good-receipt-upload{{ $goodReceiptExists ? ' has-photo' : '' }}" id="goodReceiptUpload"
                @if($goodReceiptExists) data-good-receipt-path="{{ $barang->good_receipt_path }}" @endif>
                <div class="good-receipt-box" id="goodReceiptBox" role="button" tabindex="0" aria-label="{{ __('barang.upload_good_receipt') }}">
                    <input type="file" name="good_receipt_file" id="goodReceiptFileInput" class="good-receipt-input" accept=".jpg,.jpeg,.png,.webp" tabindex="-1">
                    <span class="good-receipt-placeholder{{ $goodReceiptExists ? ' d-none' : '' }}" aria-hidden="true">+</span>
                    <img src="{{ $goodReceiptViewUrl ?? '' }}" class="good-receipt-preview{{ $goodReceiptExists ? '' : ' d-none' }}" alt="{{ __('barang.label_good_receipt') }}">
                    <button type="button" class="btn-remove-good-receipt{{ $goodReceiptExists ? '' : ' d-none' }}" id="btnRemoveGoodReceipt" aria-label="{{ __('common.remove_file') }}">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="form-text small good-receipt-hint">{{ __('barang.hint_good_receipt') }}</div>
        </div>
    </div>

</div>

<div class="row g-3 mb-4">
    <x-form-group :label="__('barang.label_resi')" cols="col-md-6">
        <div class="input-group mb-2">
            <input type="text" class="form-control" id="resiInput" placeholder="{{ __('barang.placeholder_resi') }}">
            <button type="button" class="btn btn-accent btn-sm" id="btnAddResi"><i class="bi bi-plus-lg me-1"></i>{{ __('barang.btn_add_resi') }}</button>
        </div>
        @php
            $noResiHiddenValue = old('no_resi');
            if ($noResiHiddenValue === null) {
                $existingResi = optional($barang)->no_resi;
                if (is_array($existingResi)) {
                    $noResiHiddenValue = json_encode($existingResi, JSON_UNESCAPED_UNICODE);
                } elseif (is_string($existingResi) && $existingResi !== '') {
                    $noResiHiddenValue = $existingResi;
                } else {
                    $noResiHiddenValue = '[]';
                }
            } elseif (is_array($noResiHiddenValue)) {
                $noResiHiddenValue = json_encode($noResiHiddenValue, JSON_UNESCAPED_UNICODE);
            }
        @endphp
        <input type="hidden" name="no_resi" id="noResiHidden" value="{{ $noResiHiddenValue }}">
        <div id="resiPanel" class="barang-resi-panel barang-resi-panel--empty">
            <p id="resiEmptyState" class="barang-resi-empty mb-0">
                <i class="bi bi-receipt" aria-hidden="true"></i>
                <span>{{ __('barang.no_resi_yet') }}</span>
            </p>
            <div id="resiTagsWrap" class="barang-resi-tags d-none flex-wrap gap-1"></div>
        </div>
    </x-form-group>
    <x-form-group :label="__('barang.label_notes')" cols="col-md-6">
        <textarea name="notes" class="form-control" rows="4" placeholder="{{ __('barang.placeholder_notes') }}">{{ old('notes', optional($barang)->notes ?? '') }}</textarea>
    </x-form-group>
</div>

<hr style="border-color:var(--glass-border)">
<div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="fw-700 mb-0" style="color:var(--text-primary)"><i class="bi bi-tags me-1"></i>{{ __('barang.additional_prices_section') }}</h6>
    <button type="button" class="btn btn-accent btn-sm" id="btnAddAdditionalPriceRow">
        <i class="bi bi-plus-circle me-1"></i>{{ __('barang.add_additional_price_row') }}
    </button>
</div>
<p class="small text-muted mb-2">{{ __('barang.additional_prices_hint') }}</p>
<div class="table-responsive mb-4 w-100">
    <table class="table table-sm table-glass mb-0 w-100" id="additionalPriceTable">
        <thead>
            <tr>
                <th style="width:3rem">#</th>
                <th>{{ __('barang.additional_price_name') }}</th>
                <th style="width:16rem">{{ __('barang.additional_price_amount') }}</th>
                <th style="width:3rem"></th>
            </tr>
        </thead>
        <tbody id="additionalPriceBody"></tbody>
    </table>
</div>

<hr style="border-color:var(--glass-border)">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-700 mb-0" style="color:var(--text-primary)"><i class="bi bi-boxes me-1"></i>{{ __('barang.products_section') }}</h6>
    <button type="button" class="btn btn-accent btn-sm js-add-product-row" id="btnAddProductRow"><i class="bi bi-plus-circle me-1"></i>{{ __('barang.add_product_row') }}</button>
</div>
<p class="small text-muted mb-2">{{ __('barang.products_hint') }} {{ __('barang.products_mixed_hint') }}</p>
<div class="product-scroll-wrap">
    <table class="table table-sm table-glass" id="productTable" style="font-size:.72rem;min-width:1800px;margin-bottom:0">
        <thead>
            <tr>
                <th style="width:24px">#</th>
                <th style="width:32px" class="text-center" title="{{ __('barang.add_product_sub') }}"></th>
                <th>{{ __('barang.prod_name') }}</th>
                <th>{{ __('barang.prod_ctns') }}</th>
                <th style="width:88px">{{ __('barang.prod_photo') }}</th>
                <th>{{ __('barang.prod_material') }}</th>
                <th>{{ __('barang.prod_pcs') }}</th>
                <th>{{ __('barang.prod_gross') }}</th>
                <th>{{ __('barang.prod_length') }}</th>
                <th>{{ __('barang.prod_width') }}</th>
                <th>{{ __('barang.prod_height') }}</th>
                <th>{{ __('barang.prod_cbm') }}</th>
                <th>{{ __('barang.prod_unit_price') }}</th>
                <th>{{ __('barang.prod_total_price') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="productBody"></tbody>
        <tfoot>
            <tr class="product-add-row-tr">
                <td colspan="15" class="py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary js-add-product-row" id="btnAddProductRowInline">
                        <i class="bi bi-plus me-1"></i>{{ __('barang.add_product_row') }}
                    </button>
                </td>
            </tr>
            <tr>
                <td colspan="3">
                    <button type="button" class="btn btn-sm btn-accent" id="btnCheckTotal"><i class="bi bi-calculator me-1"></i>{{ __('barang.check_total') }}</button>
                </td>
                <td>
                    <div class="text-end small" style="color:var(--text-muted)">{{ __('barang.total_ctns') }}</div>
                    <div class="text-accent fw-700" id="totalCtns">0</div>
                </td>
                <td colspan="3"></td>
                <td>
                    <div class="text-end small" style="color:var(--text-muted)">{{ __('barang.total_gw') }}</div>
                    <div class="text-accent fw-700" id="totalGW">0</div>
                </td>
                <td colspan="3"></td>
                <td>
                    <div class="text-end small" style="color:var(--text-muted)">{{ __('barang.total_cbm') }}</div>
                    <div class="text-accent fw-700" id="totalCbm">0</div>
                </td>
                <td></td>
                <td>
                    <div class="text-end small" style="color:var(--text-muted)">{{ __('barang.total_rmb') }}</div>
                    <div class="text-accent fw-700" id="totalRmb">0</div>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
