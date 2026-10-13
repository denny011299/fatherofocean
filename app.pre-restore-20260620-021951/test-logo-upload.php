<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Branding;
use Illuminate\Http\UploadedFile;

$jpegPath = storage_path('app/test-logo.jpg');
$im = imagecreatetruecolor(32, 32);
imagefill($im, 0, 0, imagecolorallocate($im, 0, 100, 200));
imagejpeg($im, $jpegPath, 90);
imagedestroy($im);

$file = new UploadedFile($jpegPath, 'WhatsApp Image 2026-05-16 at 08.47.29.jpeg', 'image/jpeg', null, true);
$stored = Branding::storeLogo($file);

echo "stored: {$stored}\n";
echo "url: " . Branding::logoUrl() . "\n";
echo "exists: " . (Branding::hasLogo() ? 'yes' : 'no') . "\n";
