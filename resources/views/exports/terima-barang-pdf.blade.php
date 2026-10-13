<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Terima Barang — {{ $barang->no_index }}</title>
    <style>
        @page { margin: 8mm 10mm; size: A4 landscape; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #334155;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        @if(!empty($hasCjkFont) && !empty($cjkFontRelative))
        @font-face {
            font-family: '{{ \App\Support\TerimaBarangPdfFonts::FAMILY }}';
            font-style: normal;
            font-weight: normal;
            src: url('{{ $cjkFontRelative }}') format('truetype');
        }
        .cjk-block {
            font-family: '{{ \App\Support\TerimaBarangPdfFonts::FAMILY }}', 'DejaVu Sans', sans-serif !important;
            font-weight: normal !important;
            font-style: normal !important;
            text-transform: none !important;
        }
        .info-label .cjk-block,
        .marketing-label .cjk-block,
        .receipt-title .cjk-block {
            font-weight: normal !important;
        }
        @endif
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }

        .doc-header {
            width: 100%;
            margin-bottom: 16px;
            border-collapse: collapse;
        }
        .doc-header td {
            vertical-align: middle;
            padding: 0;
        }
        .doc-header-logo img {
            height: 52px;
            width: auto;
            max-width: 260px;
            display: block;
        }
        .doc-header-logo .brand-fallback {
            font-size: 22px;
            font-weight: 700;
            color: #172030;
            letter-spacing: -0.3px;
        }
        .doc-header-logo .brand-dot { color: #f59e0b; font-size: 24px; }
        .doc-title-wrap {
            text-align: right;
            line-height: 1.15;
        }
        .doc-title-main {
            font-size: 16px;
            font-weight: 700;
            color: #172030;
            letter-spacing: 0.01em;
        }
        .doc-title-sub {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            margin-top: 3px;
        }

        .info-grid {
            width: 100%;
            margin-bottom: 22px;
            border-collapse: separate;
            border-spacing: 10px 0;
            table-layout: fixed;
        }
        .info-grid > tbody > tr > td {
            width: 50%;
            vertical-align: top;
            padding: 0;
            height: 248px;
        }
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 14px;
            height: 248px;
            box-sizing: border-box;
        }
        .info-card-inner {
            height: 220px;
        }
        .info-row { width: 100%; margin-bottom: 8px; }
        .info-row td { padding: 0 0 6px; vertical-align: top; border-bottom: 1px dashed #e2e8f0; }
        .info-row:last-child td { border-bottom: none; padding-bottom: 0; }
        .info-label {
            color: #64748b;
            font-weight: normal;
            font-size: 10px;
            text-transform: none;
            width: 55%;
        }
        .info-val {
            font-weight: 700;
            color: #172030;
            text-align: right;
            width: 45%;
        }

        .receipt-card { text-align: center; }
        .receipt-card .info-card-inner {
            width: 100%;
            height: 220px;
            padding-top: 4px;
        }
        .receipt-card-body {
            text-align: center;
        }
        .info-card-meta .info-row td {
            padding-bottom: 7px;
        }
        .info-val-multi {
            text-align: right;
            line-height: 1.35;
            word-wrap: break-word;
        }
        .receipt-photo-label {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 6px;
            text-align: center;
        }
        .receipt-photo-box {
            border: 2px dashed #cbd5e1;
            border-radius: 6px;
            height: 188px;
            background: #fff;
            margin: 0 auto;
            text-align: center;
            vertical-align: middle;
            width: 100%;
        }
        .receipt-photo-box td {
            height: 184px;
            text-align: center;
            vertical-align: middle;
            color: #64748b;
            font-size: 10px;
        }
        .receipt-photo-box img {
            max-width: 100%;
            max-height: 176px;
            display: block;
            margin: 0 auto;
        }
        .marketing-row { font-size: 11px; }
        .marketing-label { color: #64748b; font-weight: 600; text-transform: none; font-size: 10px; }
        .marketing-name { color: #f59e0b; font-weight: 700; font-size: 12px; }

        table.data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        table.data th,
        table.data td {
            padding: 8px 5px;
            vertical-align: middle;
            text-align: center;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-size: 10px;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            color: #334155;
        }
        table.data.packing-table td.col-desc {
            text-align: left !important;
            padding-left: 10px !important;
            padding-right: 8px !important;
            vertical-align: middle !important;
        }
        table.data.packing-table th.col-desc {
            text-align: left !important;
            padding-left: 10px !important;
        }
        table.data td.col-photo {
            padding: 6px 4px !important;
            width: 52px;
            vertical-align: middle !important;
        }
        table.data th:last-child,
        table.data td:last-child { border-right: none; }
        table.data tbody tr:last-child td { border-bottom: none; }
        table.data th {
            background-color: #172030;
            color: #fff;
            font-weight: 700;
            font-size: 10px;
            text-transform: none;
            letter-spacing: 0.3px;
            vertical-align: middle;
        }
        table.data tbody tr.row-alt td { background-color: #f8fafc; }

        h2 {
            text-align: center;
            color: #172030;
            font-size: 16px;
            margin: 0 0 14px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .text-left { text-align: left !important; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }
        .bold { font-weight: 700; }
        .text-orange { color: #f59e0b; font-weight: 700; }
        .multi-line { white-space: pre-line; line-height: 1.5; }
        .desc-sub { color: #64748b; font-size: 9px; display: block; margin-top: 2px; }

        .thumb-frame {
            width: 48px;
            height: 48px;
            margin: 0 auto;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 4px;
            table-layout: fixed;
        }
        .thumb-frame td {
            width: 48px;
            height: 48px;
            text-align: center;
            vertical-align: middle;
            padding: 2px;
        }
        .thumb-frame img {
            max-width: 44px;
            max-height: 44px;
            width: auto;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        table.data.packing-photo-table th,
        table.data.packing-photo-table td {
            padding: 3px 2px !important;
        }
        table.data.packing-photo-table td.col-photo {
            width: auto;
            padding: 2px !important;
        }
        .thumb-proof {
            width: 168px;
            height: 168px;
            margin: 0 auto;
            border: none;
            background: transparent;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .thumb-proof td {
            width: 168px;
            height: 168px;
            text-align: center;
            vertical-align: middle;
            padding: 0;
            border: none;
        }
        .thumb-proof img {
            max-width: 168px;
            max-height: 168px;
            width: auto;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        .total-row td { font-weight: 700; background: #f8fafc !important; vertical-align: middle !important; }
        table.data.packing-table .total-row td.col-total-label {
            text-align: right !important;
            padding-right: 8px !important;
        }
        .total-amount { font-size: 12px; color: #f59e0b; white-space: nowrap; }
        table.data td.col-money { white-space: nowrap; font-size: 9px; }

        .invoice-embed { text-align: center; margin-top: 8px; }
        .invoice-embed img { max-width: 100%; height: auto; max-height: 250mm; }
    </style>
</head>
<body>
{!! \App\Support\TerimaBarangPdfData::cjkPreloadHtml(!empty($hasCjkFont)) !!}

<div class="page">
    <table class="doc-header">
        <tr>
            <td class="doc-header-logo" width="50%" align="left">
                @if($logoPath)
                <img src="{{ $logoPath }}" alt="Ocean Link">
                @else
                <span class="brand-fallback"><span class="brand-dot">&#9679;</span> OCEAN LINK</span>
                @endif
            </td>
            <td width="50%" align="right" valign="middle">
                <div class="doc-title-wrap">
                    <div class="doc-title-main">
                        {!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Tanda Terima Barang', '货物签收', false) !!}
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <table class="info-grid">
        <tr>
            <td>
                <div class="info-card info-card-meta">
                    <div class="info-card-inner">
                    <table class="w100" style="width:100%;height:220px">
                        <tr class="info-row">
                            <td class="info-label">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'CL', '客户') !!}</td>
                            <td class="info-val">{{ $markingCode }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Date of Entry', '入库日期') !!}</td>
                            <td class="info-val">{{ $entryDateLabel }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Supplier', '供应商') !!}</td>
                            <td class="info-val">{{ $supplierDisplay }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Tracking Number', '运单') !!}</td>
                            <td class="info-val">{{ $trackingNumber }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Index', '流水号') !!}</td>
                            <td class="info-val">{{ $barang->no_index }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Shipping Address', '收货地址') !!}</td>
                            <td class="info-val info-val-multi">{{ $customerShippingAddress }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Marketing', '业务') !!}</td>
                            <td class="info-val marketing-name">{{ $marketingName }}</td>
                        </tr>
                    </table>
                    </div>
                </div>
            </td>
            <td>
                <div class="info-card receipt-card">
                    <div class="info-card-inner">
                    <div class="receipt-card-body">
                    <div class="receipt-photo-label">
                        {!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Goods Receipt Photo', '收货照片') !!}
                    </div>
                    <table class="receipt-photo-box w100" align="center" cellpadding="0" cellspacing="0" style="width:100%">
                        <tr>
                            <td>
                                @if($goodReceiptPath)
                                <img src="{{ $goodReceiptPath }}" alt="Good receipt">
                                @else
                                [ Photo Area ]
                                @endif
                            </td>
                        </tr>
                    </table>
                    </div>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th class="text-left" style="width:28%">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Items', '商品') !!}</th>
                <th style="width:8%">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Coly', '箱数') !!}</th>
                <th style="width:8%">P<br/><span style="font-weight:normal;font-size:8px">(cm)</span></th>
                <th style="width:8%">L<br/><span style="font-weight:normal;font-size:8px">(cm)</span></th>
                <th style="width:8%">T<br/><span style="font-weight:normal;font-size:8px">(cm)</span></th>
                <th style="width:12%">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Volume', '体积') !!}</th>
                <th style="width:12%">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Weight', '重量') !!}</th>
                <th style="width:16%">{!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Notes', '备注') !!}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($receiptRows as $row)
            <tr>
                <td class="text-left multi-line">{{ $row['items_display'] }}</td>
                <td class="bold">@if($row['coly'] !== ''){{ $row['coly'] }}@else&nbsp;@endif</td>
                <td class="bold">@if(($row['length'] ?? '') !== ''){{ $row['length'] }}@else&nbsp;@endif</td>
                <td class="bold">@if(($row['width'] ?? '') !== ''){{ $row['width'] }}@else&nbsp;@endif</td>
                <td class="bold">@if(($row['height'] ?? '') !== ''){{ $row['height'] }}@else&nbsp;@endif</td>
                <td class="bold multi-line">{{ $row['volume'] }}</td>
                <td class="bold multi-line">{{ $row['weight'] }}</td>
                <td class="multi-line">{{ $row['notes'] }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td class="text-right bold">SUMMARY</td>
                <td class="bold">{{ $colyCount }}</td>
                <td class="bold">{{ $summaryLength ?? '-' }}</td>
                <td class="bold">{{ $summaryWidth ?? '-' }}</td>
                <td class="bold">{{ $summaryHeight ?? '-' }}</td>
                <td class="bold">{{ $volumeValue }}</td>
                <td class="bold">{{ $weightValue }}</td>
                <td class="text-center bold">-</td>
            </tr>
        </tbody>
    </table>
</div>

@if($showPackingTable)
<div class="page">
    <h2>
        {!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Invoice & Packing List', '发票及装箱单') !!}
    </h2>
    <table class="data packing-table">
        <thead>
            <tr>
                <th style="width:5%">CTNS</th>
                <th style="width:7%">Photo</th>
                <th class="col-desc" style="width:20%">Item name</th>
                <th style="width:5%">P</th>
                <th style="width:5%">L</th>
                <th style="width:5%">T</th>
                <th style="width:8%">Qty/Box</th>
                <th style="width:8%">Weight</th>
                <th style="width:7%">VOL.</th>
                <th style="width:8%">UNIT PRICE</th>
                <th class="text-right" style="width:10%">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            @php $rowIndex = 0; @endphp
            @foreach($packingGroups as $group)
                @foreach($group['items'] as $itemIndex => $item)
                <tr class="{{ $rowIndex % 2 === 1 ? 'row-alt' : '' }}">
                    @if($itemIndex === 0)
                    <td class="bold" rowspan="{{ $group['rowspan'] }}">{{ $group['ctns'] }}</td>
                    @endif
                    <td class="col-photo">
                        @if(!empty($item['photo_uri']))
                        <table class="thumb-frame" role="presentation"><tr><td><img src="{{ $item['photo_uri'] }}" alt=""></td></tr></table>
                        @else
                        —
                        @endif
                    </td>
                    <td class="col-desc multi-line">
                        {{ $item['description'] }}
                        @if(!empty($item['description_sub']))
                        <span class="desc-sub">({{ $item['description_sub'] }})</span>
                        @endif
                    </td>
                    @if($itemIndex === 0)
                    <td rowspan="{{ $group['rowspan'] }}">{{ $group['length'] ?? '-' }}</td>
                    <td rowspan="{{ $group['rowspan'] }}">{{ $group['width'] ?? '-' }}</td>
                    <td rowspan="{{ $group['rowspan'] }}">{{ $group['height'] ?? '-' }}</td>
                    @endif
                    <td class="multi-line">{{ $item['quantity_per_box'] }}</td>
                    @if($itemIndex === 0)
                    <td class="multi-line" rowspan="{{ $group['rowspan'] }}">{{ $group['weight'] }}</td>
                    <td class="multi-line" rowspan="{{ $group['rowspan'] }}" style="font-size:9px">{{ $group['vol'] }}</td>
                    @endif
                    <td class="col-money">{{ $item['unit_price'] }}</td>
                    <td class="text-right bold col-money">{{ $item['amount'] }}</td>
                </tr>
                @php $rowIndex++; @endphp
                @endforeach
            @endforeach
            <tr class="total-row">
                <td colspan="3" class="col-total-label bold">TOTAL</td>
                <td colspan="3">&nbsp;</td>
                <td class="bold">{{ $packingTotals['qty'] }}</td>
                <td colspan="2">&nbsp;</td>
                <td>&nbsp;</td>
                <td class="text-right bold total-amount">
                    @if((float) $packingTotals['amount'] > 0)
                        {{ \App\Support\TerimaBarangPdfData::formatRmb((float) $packingTotals['amount']) }}
                    @else
                        -
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
</div>
@endif

@if($showDimensionProofPage)
<div class="page">
    <h2>
        {!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Dimension Proof Photos', '尺寸证明照片') !!}
    </h2>
    <table class="data packing-table packing-photo-table">
        <thead>
            <tr>
                <th style="width:8%">CTNS</th>
                <th style="width:18%">PHOTO</th>
                <th style="width:18%">P (Photo)</th>
                <th style="width:18%">L (Photo)</th>
                <th style="width:18%">T (Photo)</th>
            </tr>
        </thead>
        <tbody>
            @php $photoRowIndex = 0; @endphp
            @foreach($packingGroups as $group)
                @foreach($group['items'] as $itemIndex => $item)
                <tr class="{{ $photoRowIndex % 2 === 1 ? 'row-alt' : '' }}">
                    @if($itemIndex === 0)
                    <td class="bold" rowspan="{{ $group['rowspan'] }}">{{ $group['ctns'] }}</td>
                    @endif
                    <td class="col-photo">
                        @if(!empty($item['photo_proof_uri']) || !empty($item['photo_uri']))
                        <table class="thumb-proof" role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td><img src="{{ $item['photo_proof_uri'] ?? $item['photo_uri'] }}" alt=""></td></tr></table>
                        @else
                        —
                        @endif
                    </td>
                    @if($itemIndex === 0)
                    <td class="col-photo" rowspan="{{ $group['rowspan'] }}">
                        @if(!empty($group['length_photo_uri']))
                        <table class="thumb-proof" role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td><img src="{{ $group['length_photo_uri'] }}" alt=""></td></tr></table>
                        @else
                        —
                        @endif
                    </td>
                    <td class="col-photo" rowspan="{{ $group['rowspan'] }}">
                        @if(!empty($group['width_photo_uri']))
                        <table class="thumb-proof" role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td><img src="{{ $group['width_photo_uri'] }}" alt=""></td></tr></table>
                        @else
                        —
                        @endif
                    </td>
                    <td class="col-photo" rowspan="{{ $group['rowspan'] }}">
                        @if(!empty($group['height_photo_uri']))
                        <table class="thumb-proof" role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td><img src="{{ $group['height_photo_uri'] }}" alt=""></td></tr></table>
                        @else
                        —
                        @endif
                    </td>
                    @endif
                </tr>
                @php $photoRowIndex++; @endphp
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
@endif

@if($showInvoiceImagePage)
<div class="page">
    <h2>
        {!! \App\Support\TerimaBarangPdfData::bilingualLabelHtml($hasCjkFont, 'Invoice & Packing List', '发票及装箱单') !!}
    </h2>
    <div class="invoice-embed">
        <img src="{{ $invoiceImagePath }}" alt="Invoice &amp; Packing List">
    </div>
</div>
@endif

</body>
</html>
