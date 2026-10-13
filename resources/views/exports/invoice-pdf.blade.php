<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ !empty($partai) ? 'Invoice - '.$partai : (isset($invoice) ? 'Invoice - '.$invoice->displayInvoiceNo() : 'Invoice') }}</title>
    <style>
        @page { margin: 6mm 8mm; size: A4 landscape; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            color: #0d1e30;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .invoice-container { width: 100%; }
        .serif { font-family: 'DejaVu Serif', 'Times New Roman', Times, serif; }
        .sans { font-family: 'DejaVu Sans', Arial, sans-serif; }
        .text-right { text-align: right; }
        .bg-shaded { background-color: #eef1f6; }
        .header-top { width: 100%; border-collapse: collapse; background-color: #eef1f6; border: 1px solid #8a9bae; border-bottom: none; }
        .header-top td { vertical-align: middle; }
        .logo-col { width: 22%; text-align: center; padding: 6px 6px; border-right: 1px solid #8a9bae; }
        .logo-col img { max-width: 72px; max-height: 54px; }
        .logo-circle { width: 54px; height: 54px; background-color: #0d1e30; border-radius: 27px; margin: 0 auto; }
        .logo-text { font-size: 9px; font-weight: bold; margin-bottom: 4px; }
        .invoice-title-col { width: 78%; text-align: center; padding: 6px; }
        .invoice-title-col h1 { font-size: 32px; font-weight: normal; letter-spacing: 2px; margin: 0; color: #0d1e30; }
        .header-bottom-bar { height: 6px; background-color: #eef1f6; border: 1px solid #8a9bae; margin-top: 2px; margin-bottom: 4px; }
        .info-row-1 { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .info-row-1 td { border: 1px solid #8a9bae; text-align: center; padding: 5px 4px; font-size: 9px; vertical-align: middle; }
        .info-row-1 .info-label { font-size: 8px; color: #64748b; margin-bottom: 1px; }
        .info-row-1 .info-value { font-weight: bold; }
        .info-row-2 { width: 100%; border-collapse: collapse; margin-bottom: 4px; table-layout: fixed; }
        .info-row-2 .deliver-col { width: 62%; border: 1px solid #8a9bae; vertical-align: top; }
        .info-row-2 .deliver-header { background-color: #eef1f6; padding: 5px 8px; font-size: 12px; border-bottom: 1px solid #8a9bae; }
        .info-row-2 .deliver-body { padding: 6px 8px; font-size: 9px; font-weight: bold; line-height: 1.35; }
        .info-row-2 .letterhead-meta-col { width: 38%; border: 1px solid #8a9bae; border-left: none; vertical-align: top; }
        .info-row-2 .letterhead-meta-col table { width: 100%; border-collapse: collapse; }
        .info-row-2 .letterhead-meta-col td { padding: 5px 8px; font-size: 9px; border-bottom: 1px solid #8a9bae; vertical-align: middle; }
        .info-row-2 .letterhead-meta-col tr:last-child td { border-bottom: none; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 0; text-align: center; table-layout: fixed; }
        .items-table th, .items-table td { border: 1px solid #8a9bae; padding: 3px 2px; }
        .items-table th { background-color: #eef1f6; font-weight: bold; font-size: 8px; height: 24px; }
        .items-table td { font-size: 9px; height: 20px; vertical-align: middle; word-wrap: break-word; overflow: hidden; }
        .items-table td.text-right { padding-right: 8px; }
        .items-table tfoot td { font-weight: bold; background-color: #eef1f6; }
        .items-table .index-col { width: 8%; }
        .desc-col { width: 17%; text-align: left; }
        .coly-col { width: 6%; }
        .ctn-col { width: 7%; }
        .dim-col { width: 7%; }
        .vol-col { width: 11%; }
        .weight-col { width: 11%; }
        .summary-label { text-align: right; font-weight: bold; vertical-align: middle; }
        .footer-rate { text-align: center; font-weight: normal; }
        .keterangan-value { text-align: right; font-weight: bold; }
        .footer-info { width: 100%; border-collapse: collapse; page-break-before: avoid; }
        .footer-info td { border: 1px solid #8a9bae; vertical-align: middle; }
        .payment-header { width: 22%; background-color: #eef1f6; padding: 10px; font-size: 13px; text-align: center; }
        .payment-body { width: 78%; padding: 8px 12px; font-size: 10px; line-height: 1.4; }
        .payment-account-line {
            display: block;
            padding: 5px 7px;
            background: #eef1f6;
            border: 1px solid #d5dde8;
            border-radius: 4px;
            color: #0d1e30;
            font-size: 10px;
            font-weight: bold;
        }
        .payment-divider { color: #8a9bae; padding: 0 4px; }
        .payment-body ul { margin: 0; padding-left: 14px; color: #0d1e30; font-size: 9px; font-weight: bold; }
        .payment-body li { margin-bottom: 2px; }
        .promo-line { font-size: 9px; color: #475569; }
        .claim-disclaimer { font-size: 9px; color: #475569; font-style: italic; text-align: right; padding: 4px 8px; }
        .invoice-page { page-break-after: always; }
        .invoice-page:last-child { page-break-after: auto; }
        /* DomPDF: thead ulangi di halaman baru; jangan potong baris di tengah. */
        .consolidated-document .section-block { page-break-inside: auto; }
        /* Satu tabel per halaman: jangan avoid di tabel utuh (bikin loncat ke page baru & kertas kosong). */
        .items-table { page-break-inside: auto; }
        .items-table thead { display: table-header-group; }
        .items-table tfoot { display: table-footer-group; }
        .items-table tr { page-break-inside: avoid; page-break-after: auto; }
        .items-table-page { margin: 0; page-break-before: auto; page-break-after: auto; }
        .items-table-page-first { page-break-before: avoid; }
        .items-table-page-continue { page-break-after: always; }
        /* Summary/total naik ke sisa ruang halaman terakhir (jangan dipaksa page baru). */
        .items-summary-table { margin-top: 6px; page-break-before: avoid; page-break-inside: auto; }
        .items-summary-table td { font-weight: bold; background-color: #eef1f6; }
        .grand-total-wrap { margin-top: 8px; margin-bottom: 12px; page-break-before: avoid; }
        .footer-info { page-break-before: avoid; }
        .index-section-gap {
            height: 14px;
            margin: 18px 0 22px;
            border: 0;
            border-top: 2px dashed #94a3b8;
            page-break-after: avoid;
        }
        .index-block-header {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin: 0 0 10px;
            padding: 8px 10px;
            background: #eef1f6;
            border: 1px solid #8a9bae;
            color: #0d1e30;
            letter-spacing: 0.02em;
            page-break-after: avoid;
        }
        .grand-total-table {
            width: 100%;
            border-collapse: collapse;
            text-align: center;
            table-layout: fixed;
        }
        .grand-total-table th,
        .grand-total-table td {
            border: 1px solid #8a9bae;
            padding: 7px 6px;
            font-size: 10px;
        }
        .grand-total-table th {
            background: #eef1f6;
            font-weight: bold;
        }
        .grand-total-table td.amount { font-weight: bold; }
        .sheet-banner {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin: 0 0 8px 0;
            letter-spacing: 0.02em;
            color: #0d1e30;
        }
        .section-subtotal-label { font-size: 9px; color: #475569; }
    </style>
</head>
<body>
@php
    $invoiceSections = $sections ?? [[
        'invoice' => $invoice,
        'logoPath' => $logoPath ?? null,
        'markingLabel' => $markingLabel ?? '-',
        'partaiLabel' => $partaiLabel ?? '-',
        'tanggalMasukLabel' => $tanggalMasukLabel ?? '-',
        'customerName' => $customerName ?? '-',
        'customerAddress' => $customerAddress ?? '-',
        'customerContact' => $customerContact ?? '-',
        'paymentTerms' => $paymentTerms ?? '-',
        'noIndexLabel' => $noIndexLabel ?? '-',
        'invoiceDateLabel' => $invoiceDateLabel ?? '-',
        'tableRows' => $tableRows ?? [],
        'tableSummary' => $tableSummary ?? [],
        'perhitunganLabel' => $perhitunganLabel ?? '-',
        'totalLabel' => $totalLabel ?? '-',
        'promoNote' => $promoNote ?? null,
        'claimNote' => $claimNote ?? null,
        'claimRefundNote' => $claimRefundNote ?? null,
        'invoicePaymentBank' => $invoicePaymentBank ?? '-',
        'invoicePaymentAccountName' => $invoicePaymentAccountName ?? '-',
        'invoicePaymentAccountNumber' => $invoicePaymentAccountNumber ?? '-',
        'sheet_title' => $sheet_title ?? null,
        'show_payment_footer' => true,
    ]];
    $isMultiIndex = $is_multi_index ?? false;
    $showGrandFooter = $show_grand_footer ?? false;
    $grandSummary = $grand_summary ?? [];
    $documentPromoNote = $promoNote ?? null;
    $documentClaimNote = $claimNote ?? null;
    $documentClaimRefundNote = $claimRefundNote ?? null;
    $documentPaymentBank = $invoicePaymentBank ?? ($invoiceSections[0]['invoicePaymentBank'] ?? '-');
    $documentPaymentAccountName = $invoicePaymentAccountName ?? ($invoiceSections[0]['invoicePaymentAccountName'] ?? '-');
    $documentPaymentAccountNumber = $invoicePaymentAccountNumber ?? ($invoiceSections[0]['invoicePaymentAccountNumber'] ?? '-');
    $letterheadSource = $invoiceSections[0] ?? [];
    $letterhead = array_merge($letterheadSource, array_filter([
        'logoPath' => $logoPath ?? ($letterheadSource['logoPath'] ?? null),
        'markingLabel' => $markingLabel ?? ($letterheadSource['markingLabel'] ?? '-'),
        'partaiLabel' => $partaiLabel ?? ($letterheadSource['partaiLabel'] ?? '-'),
        'tanggalMasukLabel' => $tanggalMasukLabel ?? ($letterheadSource['tanggalMasukLabel'] ?? '-'),
        'customerName' => $customerName ?? ($letterheadSource['customerName'] ?? '-'),
        'customerAddress' => $customerAddress ?? ($letterheadSource['customerAddress'] ?? '-'),
        'customerContact' => $customerContact ?? ($letterheadSource['customerContact'] ?? '-'),
        'paymentTerms' => $paymentTerms ?? ($letterheadSource['paymentTerms'] ?? '-'),
        'noIndexLabel' => $noIndexLabel ?? ($letterheadSource['noIndexLabel'] ?? '-'),
        'invoiceDateLabel' => $invoiceDateLabel ?? ($letterheadSource['invoiceDateLabel'] ?? '-'),
    ], static fn ($value): bool => $value !== null));
@endphp

<div class="@if($isMultiIndex && $showGrandFooter) consolidated-document @endif">

@foreach($invoiceSections as $sectionIndex => $section)
@php
    $summary = $section['tableSummary'] ?? [];
    $shippingFooter = $section['shippingFooter'] ?? [];
    $footerColspan = 9;
    $sectionTableRows = $section['tableRows'] ?? [];
    $sectionTotalLabel = $section['totalLabel'] ?? '-';
    $sectionPromoNote = $section['promoNote'] ?? null;
    $sectionClaimNote = $section['claimNote'] ?? null;
    $sectionClaimRefundNote = $section['claimRefundNote'] ?? null;
    $sectionSheetTitle = $section['sheet_title'] ?? null;
    $sectionMarkingLabel = $section['markingLabel'] ?? '-';
    $sectionSupplierLabel = $section['supplierLabel'] ?? '-';
    $sectionNoIndexLabel = $section['noIndexLabel'] ?? '-';
    $sectionNoPoLabel = $section['noPoLabel'] ?? '-';
    $sectionIndexNum = $section['index_section'] ?? null;
    $sectionIndexTotal = $section['index_section_total'] ?? null;
    $showDocumentLetterhead = $isMultiIndex && $showGrandFooter && $sectionIndex === 0;
    $showSectionLetterhead = (! $isMultiIndex && ($section['show_letterhead'] ?? true)) || $showDocumentLetterhead;
    $showPaymentFooter = ($section['show_payment_footer'] ?? true) && ! $showGrandFooter;
    $isLastSection = $sectionIndex === count($invoiceSections) - 1;
    // "Subtotal pengiriman" hanya kalau benar-benar pecah multi frame; satu frame pakai TOTAL.
    $sectionSubtotalOnly = $isMultiIndex && $showGrandFooter && count($invoiceSections) > 1;
@endphp
<div class="@if(! $isMultiIndex) invoice-page @endif section-block">
@if(!empty($sectionSheetTitle))
    <div class="sheet-banner sans">{{ $sectionSheetTitle }}</div>
@endif
@if($isMultiIndex && $showGrandFooter && !empty($sectionIndexNum) && !empty($sectionIndexTotal))
    <div class="index-block-header sans">
        Pengiriman {{ $sectionIndexNum }} dari {{ $sectionIndexTotal }}
        &nbsp;|&nbsp;
        {{ $sectionMarkingLabel }}
        &nbsp;|&nbsp; SUPPLIER: {{ $sectionSupplierLabel }}
        &nbsp;|&nbsp; No. Index {{ $sectionNoIndexLabel }}
        @if(!empty($sectionNoPoLabel) && $sectionNoPoLabel !== '-')
            &nbsp;/&nbsp; No. PO {{ $sectionNoPoLabel }}
        @endif
    </div>
@endif
<div class="invoice-container">

    @if($showSectionLetterhead)
        @include('exports.partials.invoice-letterhead', $showDocumentLetterhead ? $letterhead : $section)
    @endif

    @php
        $renderPages = \App\Support\InvoicePdfTableSegments::build(
            $sectionTableRows,
            (bool) $showSectionLetterhead,
        );
        $displayWeight = static function (mixed $value): string {
            if ($value === null || $value === '' || $value === '-') {
                return '0';
            }

            return (string) $value;
        };
    @endphp

    @forelse($renderPages as $pageIndex => $pageGroups)
    @php
        $isLastItemsPage = $pageIndex === count($renderPages) - 1;
    @endphp
    <table class="items-table items-table-page @if($pageIndex === 0) items-table-page-first @endif @if(! $isLastItemsPage) items-table-page-continue @endif serif" cellpadding="0" cellspacing="0">
        <colgroup>
            <col style="width:8%">
            <col style="width:17%">
            <col style="width:6%">
            <col style="width:7%">
            <col style="width:7%">
            <col style="width:7%">
            <col style="width:7%">
            <col style="width:11%">
            <col style="width:11%">
            <col style="width:19%">
        </colgroup>
        <thead>
            <tr>
                <th class="index-col">Index</th>
                <th class="desc-col">Item</th>
                <th class="coly-col">Coly</th>
                <th class="ctn-col">CTN<br/>No</th>
                <th class="dim-col">P<br/>(cm)</th>
                <th class="dim-col">L<br/>(cm)</th>
                <th class="dim-col">T<br/>(cm)</th>
                <th class="vol-col">Volume</th>
                <th class="weight-col">Weight</th>
                <th class="weight-col">Total<br/>Weight</th>
            </tr>
        </thead>
        @foreach($pageGroups as $groupRows)
        @php
            $groupCount = count($groupRows);
        @endphp
        <tbody class="sans">
            @foreach($groupRows as $rowIndex => $row)
            @php
                $unitWeight = $displayWeight($row['unit_weight'] ?? ($row['weight'] ?? null));
                $totalWeight = $displayWeight($row['total_weight'] ?? ($row['weight'] ?? null));
            @endphp
            <tr>
                @if($rowIndex === 0)
                    <td class="index-col" rowspan="{{ $groupCount }}">{{ $row['index_no'] ?? '-' }}</td>
                    <td class="desc-col" rowspan="{{ $groupCount }}">{{ $row['description'] ?? '-' }}</td>
                @endif
                <td class="coly-col">{{ $row['coly'] }}</td>
                <td class="ctn-col">{{ $row['ctn_no'] }}</td>
                <td class="dim-col">{{ $row['length'] ?? '-' }}</td>
                <td class="dim-col">{{ $row['width'] ?? '-' }}</td>
                <td class="dim-col">{{ $row['height'] ?? '-' }}</td>
                <td class="vol-col">{{ $row['volume'] }}</td>
                <td class="weight-col">{{ $unitWeight }}</td>
                <td class="weight-col">{{ $totalWeight }}</td>
            </tr>
            @endforeach
        </tbody>
        @endforeach
    </table>
    @empty
    <table class="items-table serif" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th class="index-col">Index</th>
                <th class="desc-col">Item</th>
                <th class="coly-col">Coly</th>
                <th class="ctn-col">CTN<br/>No</th>
                <th class="dim-col">P<br/>(cm)</th>
                <th class="dim-col">L<br/>(cm)</th>
                <th class="dim-col">T<br/>(cm)</th>
                <th class="vol-col">Volume</th>
                <th class="weight-col">Weight</th>
                <th class="weight-col">Total<br/>Weight</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="10">&nbsp;</td>
            </tr>
        </tbody>
    </table>
    @endforelse

    {{-- Ringkasan di tabel terpisah agar thead item tidak ikut ke halaman berikutnya saat item sudah habis. --}}
    <table class="items-table items-summary-table sans" cellpadding="0" cellspacing="0" style="margin-bottom:12px">
        <tr>
            <td colspan="2" class="summary-label serif">TOTAL</td>
            <td class="coly-col">{{ $summary['coly'] ?? '-' }}</td>
            <td class="ctn-col">-</td>
            <td class="dim-col">-</td>
            <td class="dim-col">-</td>
            <td class="dim-col">-</td>
            <td class="vol-col">{{ $summary['volume'] ?? '-' }}</td>
            <td class="weight-col">0</td>
            <td class="weight-col">{{ $summary['weight'] ?? '0 kg' }}</td>
        </tr>
        <tr>
            <td colspan="{{ $footerColspan }}" class="summary-label serif bg-shaded">Keterangan</td>
            <td class="keterangan-value">{{ $shippingFooter['keterangan'] ?? '(CBM)' }}</td>
        </tr>
        @php
            $kubikasiRows = $shippingFooter['kubikasi_rows'] ?? [];
            if ($kubikasiRows === []) {
                $kubikasiRows = [[
                    'label' => $shippingFooter['kubikasi_rate'] ?? '-',
                    'amount' => $shippingFooter['kubikasi_amount'] ?? '-',
                ]];
            }
            $kubikasiRowCount = count($kubikasiRows);

            $beratRows = $shippingFooter['berat_rows'] ?? [];
            if ($beratRows === []) {
                $beratRows = [[
                    'label' => $shippingFooter['berat_rate'] ?? '-',
                    'amount' => $shippingFooter['berat_amount'] ?? '-',
                ]];
            }
            $beratRowCount = count($beratRows);
        @endphp
        @foreach($kubikasiRows as $kubikasiIndex => $kubikasiRow)
        <tr>
            @if($kubikasiIndex === 0)
            <td colspan="2" class="summary-label serif"@if($kubikasiRowCount > 1) rowspan="{{ $kubikasiRowCount }}"@endif>Harga kubikasi</td>
            @endif
            <td colspan="6" class="footer-rate">{{ $kubikasiRow['label'] ?? '-' }}</td>
            <td colspan="2" class="text-right">{{ $kubikasiRow['amount'] ?? '-' }}</td>
        </tr>
        @endforeach
        @foreach($beratRows as $beratIndex => $beratRow)
        <tr>
            @if($beratIndex === 0)
            <td colspan="2" class="summary-label serif"@if($beratRowCount > 1) rowspan="{{ $beratRowCount }}"@endif>Harga berat</td>
            @endif
            <td colspan="6" class="footer-rate">{{ $beratRow['label'] ?? '-' }}</td>
            <td colspan="2" class="text-right">{{ $beratRow['amount'] ?? '-' }}</td>
        </tr>
        @endforeach
        @foreach(($shippingFooter['additional_rows'] ?? []) as $additionalRow)
        <tr>
            <td colspan="{{ $footerColspan }}" class="summary-label serif">{{ $additionalRow['description'] }}</td>
            <td class="text-right">{{ $additionalRow['amount'] }}</td>
        </tr>
        @endforeach
        @if(! $sectionSubtotalOnly)
        @if($sectionPromoNote)
        <tr>
            <td colspan="{{ $footerColspan }}" class="text-right bg-shaded serif">Promo</td>
            <td class="text-right promo-line">{{ $sectionPromoNote }}</td>
        </tr>
        @endif
        @if(!empty($sectionClaimNote))
        <tr>
            <td colspan="{{ $footerColspan }}" class="text-right bg-shaded serif">{{ __('finance.col_total_claim') }}</td>
            <td class="text-right">{{ $sectionClaimNote }}</td>
        </tr>
        @endif
        @if(!empty($sectionClaimRefundNote))
        <tr>
            <td colspan="{{ $footerColspan + 1 }}" class="claim-disclaimer">{{ $sectionClaimRefundNote }}</td>
        </tr>
        @endif
        @endif
        <tr>
            <td colspan="{{ $footerColspan }}" class="text-right bg-shaded serif">
                @if($sectionSubtotalOnly)
                    <span class="section-subtotal-label">Subtotal pengiriman</span>
                @else
                    TOTAL
                @endif
            </td>
            <td class="text-right"><strong>{{ $sectionTotalLabel }}</strong></td>
        </tr>
    </table>

    @if($showPaymentFooter)
    <table class="footer-info" cellpadding="0" cellspacing="0">
        <tr>
            <td class="payment-header serif">Payment Information</td>
            <td class="payment-body sans">
                <div class="payment-details">
                    <span class="payment-account-line">
                        Bank: {{ $section['invoicePaymentBank'] ?? '-' }}
                        <span class="payment-divider">|</span>
                        Nama: {{ $section['invoicePaymentAccountName'] ?? '-' }}
                        <span class="payment-divider">|</span>
                        No. Rek: {{ $section['invoicePaymentAccountNumber'] ?? '-' }}
                    </span>
                </div>
                <ul>
                    <li>pembayaran di lakukan setelah barang bongkaran</li>
                    <li>komplain barang maximal 3 hari setelah di terima (tolong sertakan video kirim)</li>
                </ul>
            </td>
        </tr>
    </table>
    @endif

</div>
</div>
@if($isMultiIndex && $showGrandFooter && ! $isLastSection)
<div class="index-section-gap"></div>
@endif
@endforeach

@if($showGrandFooter && !empty($grandSummary))
<div class="grand-total-wrap sans">
    <table class="grand-total-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th>CTNS</th>
                <th>VOLUME</th>
                <th>GW</th>
                <th>ONG. KIRIM</th>
                <th>{{ __('finance.col_total_claim') }}</th>
                <th>{{ __('finance.col_grand_total') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $grandSummary['coly'] ?? '-' }}</td>
                <td>{{ $grandSummary['volume'] ?? '-' }}</td>
                <td>{{ $grandSummary['weight'] ?? '-' }}</td>
                <td class="amount">{{ $grandSummary['perhitungan'] ?? '-' }}</td>
                <td class="amount">{{ $grandSummary['claim_total'] ?? '-' }}</td>
                <td class="amount">{{ $grandSummary['total'] ?? '-' }}</td>
            </tr>
        </tbody>
    </table>
    @if(!empty($documentClaimRefundNote))
    <div class="claim-disclaimer" style="margin-top:6px">{{ $documentClaimRefundNote }}</div>
    @endif
    @if(!empty($documentPromoNote))
    <table class="grand-total-table sans" cellpadding="0" cellspacing="0" style="margin-top:6px">
        <tbody>
            <tr>
                <td colspan="5" class="text-right">Promo</td>
                <td class="amount promo-line">{{ $documentPromoNote }}</td>
            </tr>
        </tbody>
    </table>
    @endif
</div>

<table class="footer-info" cellpadding="0" cellspacing="0">
    <tr>
        <td class="payment-header serif">Payment Information</td>
        <td class="payment-body sans">
            <div class="payment-details">
                <span class="payment-account-line">
                    Bank: {{ $documentPaymentBank }}
                    <span class="payment-divider">|</span>
                    Nama: {{ $documentPaymentAccountName }}
                    <span class="payment-divider">|</span>
                    No. Rek: {{ $documentPaymentAccountNumber }}
                </span>
            </div>
            <ul>
                <li>pembayaran di lakukan setelah barang bongkaran</li>
                <li>komplain barang maximal 3 hari setelah di terima (tolong sertakan video kirim)</li>
            </ul>
        </td>
    </tr>
</table>
@endif
</div>
</body>
</html>
