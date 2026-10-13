<?php

/**
 * Recovery TANPA Laravel bootstrap — HAPUS setelah situs normal.
 *
 * Coba URL (satu per satu):
 *   https://oceanlink.my.id/recover.php?token=oceanlink-recover-2026
 *   https://oceanlink.my.id/public/recover.php?token=oceanlink-recover-2026
 */

declare(strict_types=1);

$token = 'oceanlink-recover-2026';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden.\n\nToken salah.\nContoh:\n?token={$token}\n");
}

header('Content-Type: text/plain; charset=utf-8');

/** Folder Laravel (parent dari public/) */
$root = dirname(__DIR__);

if (! is_file($root.'/artisan')) {
    $root = __DIR__;
}

if (! is_file($root.'/artisan')) {
    exit("ERROR: folder Laravel tidak ditemukan.\nroot dicoba: {$root}\n");
}

$results = [];
$results[] = 'Laravel root: '.$root;

$deleteFiles = static function (string $pattern) use (&$results): int {
    $count = 0;
    foreach (glob($pattern) ?: [] as $path) {
        if (is_file($path) && @unlink($path)) {
            $results[] = 'deleted: '.$path;
            $count++;
        }
    }

    return $count;
};

$results[] = 'routes cache: '.$deleteFiles($root.'/bootstrap/cache/routes-*.php').' file';
$results[] = 'config cache: '.(@unlink($root.'/bootstrap/cache/config.php') ? '1 file' : '0 file');

$viewsDir = $root.'/storage/framework/views';
$viewCount = 0;
if (is_dir($viewsDir)) {
    foreach (glob($viewsDir.'/*') ?: [] as $path) {
        if (is_file($path) && @unlink($path)) {
            $viewCount++;
        }
    }
}
$results[] = "view cache: {$viewCount} file";

$cacheDir = $root.'/storage/framework/cache/data';
$cacheCount = 0;
if (is_dir($cacheDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isFile() && @unlink($item->getPathname())) {
            $cacheCount++;
        }
    }
}
$results[] = "app cache: {$cacheCount} file";

echo "Recovery selesai (standalone, tanpa artisan).\n\n";
echo implode("\n", $results);
echo "\n\n1. Refresh https://oceanlink.my.id\n";
echo "2. HAPUS file recover.php dari server\n";
