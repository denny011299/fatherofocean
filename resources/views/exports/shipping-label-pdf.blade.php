<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Barcode - {{ $noIndex }}</title>
    <style>
        @page {
            margin: 0;
            size: 294.8pt 85.04pt;
        }

        html, body {
            margin: 0;
            padding: 0;
        }

        .page {
            width: 294.8pt;
            height: 85.04pt;
            position: relative;
            page-break-inside: avoid;
            page-break-after: always;
            overflow: hidden;
        }

        .page.last {
            page-break-after: auto;
        }

        img.label-bg {
            display: block;
            width: 294.8pt;
            height: 85.04pt;
        }

        img.barcode-img {
            position: absolute;
            top: 2pt;
            left: 7pt;
            width: 280pt;
            height: 30pt;
        }

        table.fallback {
            width: 294.8pt;
            height: 85.04pt;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-inside: avoid;
            page-break-after: always;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8pt;
        }

        table.fallback.last {
            page-break-after: auto;
        }

        table.fallback td {
            border: 0.75pt solid #000;
            text-align: center;
            vertical-align: middle;
            padding: 0;
        }

        tr.barcode-row {
            height: 36pt;
        }

        tr.marking-row {
            height: 22pt;
        }

        tr.footer-row {
            height: 27pt;
        }

        tr.footer-row td {
            width: 50%;
        }

        table.fallback img.barcode-img {
            position: static;
            display: block;
            width: 280pt;
            height: 30pt;
            margin: 0 auto;
        }
    </style>
</head>
<body>
@php
    $barcodeSrc = $barcodePath ?? $barcodeDataUri ?? $barcodeTecItUrl ?? null;
@endphp
@foreach($labels as $label)
    @if(!empty($label['image']))
<div class="page @if($loop->last) last @endif"><img class="label-bg" src="{{ $label['image'] }}"/>@if(!empty($barcodeSrc))<img class="barcode-img" src="{{ $barcodeSrc }}"/>@endif</div>
    @else
        <table class="fallback @if($loop->last) last @endif" cellspacing="0" cellpadding="0"><tr class="barcode-row"><td colspan="2">@if(!empty($barcodeSrc))<img class="barcode-img" src="{{ $barcodeSrc }}"/>@endif</td></tr><tr class="marking-row"><td colspan="2">{{ $markingLabel }}</td></tr><tr class="footer-row"><td>{{ $noIndex }}</td><td>{{ $label['coly_display'] }}</td></tr></table>
    @endif
@endforeach
</body>
</html>
