<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Tanda Terima Barang</title>
    <style>
        @page {
            size: A5 landscape;
            margin: 6mm 8mm;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 8.5pt;
            line-height: 1.2;
            color: #000;
            margin: 0;
            padding: 0;
        }

        .page {
            page-break-after: always;
            width: 100%;
        }

        .page:last-child {
            page-break-after: auto;
        }

        .doc-title {
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            letter-spacing: 0.04em;
            margin: 0 0 3mm 0;
            text-decoration: underline;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3mm;
        }

        .meta-table td {
            vertical-align: top;
            padding: 0.5mm 0;
            width: 50%;
        }

        .meta-label {
            font-weight: bold;
            display: inline-block;
            min-width: 16mm;
        }

        .meta-value {
            display: inline-block;
            min-width: 55mm;
            border-bottom: 0.35pt solid #000;
            padding: 0 1mm 0.3mm 1mm;
            min-height: 4mm;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .items-table th,
        .items-table td {
            border: 0.45pt solid #000;
            padding: 1mm 1.2mm;
            vertical-align: top;
            word-wrap: break-word;
        }

        .items-table th {
            font-weight: bold;
            text-align: center;
            font-size: 7.8pt;
        }

        .col-index { width: 10%; text-align: center; }
        .col-nama { width: 34%; }
        .col-colli { width: 8%; text-align: center; }
        .col-cbm { width: 12%; text-align: right; }
        .col-gw { width: 12%; text-align: right; }
        .col-ket { width: 24%; }

        .items-table tbody tr.empty-row td {
            height: 5mm;
        }

        .items-table tfoot td {
            font-weight: bold;
        }

        .footer-row {
            margin-top: 4mm;
            width: 100%;
        }

        .footer-row:after {
            content: '';
            display: table;
            clear: both;
        }

        .footer-sign {
            float: right;
            width: 58mm;
        }

        .footer-sign .sign-line {
            border-top: 0.45pt solid #000;
            margin-top: 12mm;
            padding-top: 1mm;
            text-align: center;
            font-size: 8pt;
            font-weight: bold;
        }

        .trip-meta {
            font-size: 7pt;
            text-align: right;
            margin-bottom: 1.5mm;
            color: #333;
        }

        .sheet-banner {
            text-align: center;
            font-size: 8.5pt;
            font-weight: bold;
            margin: 0 0 2mm 0;
            letter-spacing: 0.02em;
        }

        .partai-banner {
            text-align: center;
            font-size: 10pt;
            font-weight: bold;
            margin: 0 0 2mm 0;
            letter-spacing: 0.02em;
        }

        body.blank-template {
            font-size: 8pt;
        }

        body.blank-template .blank-template-page {
            page-break-after: avoid !important;
            page-break-inside: avoid;
        }

        body.blank-template .doc-title {
            font-size: 12pt;
            margin-bottom: 2mm;
        }

        body.blank-template .meta-table {
            margin-bottom: 2mm;
        }

        body.blank-template .meta-table td {
            padding: 0.3mm 0;
        }

        body.blank-template .meta-value {
            min-height: 3.5mm;
        }

        body.blank-template .items-table th {
            font-size: 7.5pt;
            padding: 0.7mm 1mm;
        }

        body.blank-template .items-table tbody tr.empty-row td {
            height: 3.6mm;
            padding: 0.5mm 1mm;
        }

        body.blank-template .footer-row {
            margin-top: 2.5mm;
        }

        body.blank-template .footer-sign .sign-line {
            margin-top: 8mm;
        }
    </style>
</head>
<body @if(!empty($is_blank_template)) class="blank-template" @endif>
@foreach($pages as $pageIndex => $page)
@php
    $header = $page['header'] ?? [];
    $rows = $page['rows'] ?? [];
    $totals = $page['totals'] ?? null;
    $blankRowCount = (int) ($page['blank_row_count'] ?? 0);
    if ($blankRowCount > 0) {
        $blankCount = $blankRowCount;
    } else {
        $minRows = ! empty($is_blank_template) ? 9 : 5;
        $blankCount = max(0, $minRows - count($rows));
    }
@endphp
<div class="page @if(!empty($is_blank_template)) blank-template-page @endif">
    @if(!empty($page['sheet_title'] ?? null))
    <div class="sheet-banner">{{ $page['sheet_title'] }}</div>
    @elseif(!empty($sheet_title))
    <div class="sheet-banner">{{ $sheet_title }}</div>
    @endif
    @if(!empty($header['partai_label']))
    <div class="partai-banner">{{ $header['partai_label'] }}</div>
    @endif
    @if(!empty($trip_no) && empty($is_blank_template))
    <div class="trip-meta">No. {{ $trip_no }} · {{ $printDate }}</div>
    @endif

    <div class="doc-title">TANDA TERIMA BARANG</div>

    <table class="meta-table">
        <tr>
            <td>
                <span class="meta-label">NO POL</span>
                <span class="meta-value">{{ $header['no_pol'] ?? '' }}</span>
            </td>
            <td>
                <span class="meta-label">MARK</span>
                <span class="meta-value">{{ $header['mark'] ?? '' }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="meta-label">SOPIR</span>
                <span class="meta-value">{{ $header['sopir'] ?? '' }}</span>
            </td>
            <td>
                <span class="meta-label">ALAMAT</span>
                <span class="meta-value">{{ $header['alamat'] ?? '' }}</span>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-index">INDEX</th>
                <th class="col-nama">NAMA BARANG</th>
                <th class="col-colli">COLLI</th>
                <th class="col-cbm">CBM</th>
                <th class="col-gw">GW</th>
                <th class="col-ket">KETERANGAN</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                <td class="col-index">{{ $row['index'] ?? '' }}</td>
                <td class="col-nama">{{ $row['nama_barang'] ?? '' }}</td>
                <td class="col-colli">{{ $row['colli'] ?? '' }}</td>
                <td class="col-cbm">{{ $row['cbm'] ?? '' }}</td>
                <td class="col-gw">{{ $row['gw'] ?? '' }}</td>
                <td class="col-ket">{{ $row['keterangan'] ?? '' }}</td>
            </tr>
            @empty
            @if($blankRowCount <= 0)
            <tr class="empty-row">
                <td colspan="6">&nbsp;</td>
            </tr>
            @endif
            @endforelse
            @for($i = 0; $i < $blankCount; $i++)
            <tr class="empty-row">
                <td class="col-index">&nbsp;</td>
                <td class="col-nama">&nbsp;</td>
                <td class="col-colli">&nbsp;</td>
                <td class="col-cbm">&nbsp;</td>
                <td class="col-gw">&nbsp;</td>
                <td class="col-ket">&nbsp;</td>
            </tr>
            @endfor
        </tbody>
        @if($totals !== null)
        <tfoot>
            <tr>
                <td class="col-index" style="text-align:center">TOTAL</td>
                <td class="col-nama"></td>
                <td class="col-colli" style="text-align:center">{{ $totals['colli'] ?? '' }}</td>
                <td class="col-cbm" style="text-align:right">{{ $totals['cbm'] ?? '' }}</td>
                <td class="col-gw" style="text-align:right">{{ $totals['gw'] ?? '' }}</td>
                <td class="col-ket"></td>
            </tr>
        </tfoot>
        @endif
    </table>

    @if(!empty($page['show_footer']))
    <div class="footer-row">
        <div class="footer-sign">
            <div class="sign-line">
                PENERIMA (NAMA + TT)
                @php $pagePenerima = trim((string) ($page['penerima'] ?? $penerima ?? '')); @endphp
                @if($pagePenerima !== '')
                <br><span style="font-weight:normal;font-size:7.5pt">{{ $pagePenerima }}</span>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
@endforeach
</body>
</html>
