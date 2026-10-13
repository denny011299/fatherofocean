<table class="header-top" cellpadding="0" cellspacing="0">
    <tr>
        <td class="logo-col sans">
            @if(!empty($logoPath))
                <img src="{{ $logoPath }}" alt="Logo">
            @else
                <div class="logo-text">Logo</div>
                <div class="logo-circle"></div>
            @endif
        </td>
        <td class="invoice-title-col">
            <h1 class="serif">Invoice</h1>
        </td>
    </tr>
</table>
<div class="header-bottom-bar"></div>

<table class="info-row-1 sans" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width:33.33%">
            <div class="info-label">Marking</div>
            <div class="info-value">{{ $markingLabel ?? '-' }}</div>
        </td>
        <td style="width:33.33%">
            <div class="info-label">Partai</div>
            <div class="info-value">{{ $partaiLabel ?? '-' }}</div>
        </td>
        <td style="width:33.34%">
            <div class="info-label">Tanggal Masuk Gudang China</div>
            <div class="info-value">{{ $tanggalMasukLabel ?? '-' }}</div>
        </td>
    </tr>
</table>

<table class="info-row-2" cellpadding="0" cellspacing="0">
    <tr>
        <td class="deliver-col" style="width:62%">
            <div class="deliver-header serif">Deliver to :</div>
            <div class="deliver-body sans">
                <div>Customer's Name: {{ $customerName ?? '-' }}</div>
                <div>Address: {{ $customerAddress ?? '-' }}</div>
                <div>Contact : {{ $customerContact ?? '-' }}</div>
                <div>Terms pembayaran : {{ $paymentTerms ?? '-' }}</div>
            </div>
        </td>
        <td class="letterhead-meta-col" style="width:38%">
            <table cellpadding="0" cellspacing="0">
                <tr>
                    <td class="bg-shaded serif">No Index</td>
                </tr>
                <tr>
                    <td class="sans">{{ $noIndexLabel ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="bg-shaded serif">Invoice Date: ( invoice terbit)</td>
                </tr>
                <tr>
                    <td class="sans">{{ $invoiceDateLabel ?? '-' }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
