<?php

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$barang = App\Models\Barang::with(['products', 'additionalPrices'])->first();
if ($barang === null) {
    fwrite(STDERR, "no barang\n");
    exit(1);
}

$payload = App\Support\TerimaBarangPdfData::forBarang($barang);
$fontDir = storage_path('fonts');
$pdf = Barryvdh\DomPDF\Facade\Pdf::loadView('exports.terima-barang-pdf', $payload)
    ->setPaper('a4', 'portrait')
    ->setOption('fontDir', $fontDir)
    ->setOption('fontCache', $fontDir)
    ->setOption('chroot', base_path());

$out = storage_path('app/test-terima.pdf');
file_put_contents($out, $pdf->output());
echo 'written: '.$out.' size='.filesize($out)."\n";
