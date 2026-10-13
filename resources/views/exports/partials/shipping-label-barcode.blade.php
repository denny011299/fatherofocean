{{-- DomPDF: PNG / URL gambar lebih andal daripada SVG --}}
@php
    $barcodeSrc = $barcodeDataUri ?? null;
    $barcodeRemote = $barcodeTecItUrl ?? null;
@endphp
@if(!empty($barcodeSrc))
    <img class="{{ trim(($imgClass ?? '').' barcode-img') }}" src="{{ $barcodeSrc }}" alt="">
@elseif(!empty($barcodeRemote))
    <img class="{{ trim(($imgClass ?? '').' barcode-img') }}" src="{{ $barcodeRemote }}" alt="">
@elseif(!empty($barcodeSvg))
    <div class="barcode-svg-wrap">
        <div class="barcode-svg">{!! $barcodeSvg !!}</div>
    </div>
@endif
@if(!empty($showNumber))
    <div class="barcode-num {{ $numClass ?? '' }}">{{ $noIndex }}</div>
@endif
