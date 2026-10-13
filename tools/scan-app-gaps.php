#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$appDir = $root . '/app';

// Collect all PHP files currently on disk
$onDisk = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile()) {
        $onDisk[] = str_replace('\\', '/', substr($file->getPathname(), strlen($appDir) + 1));
    }
}
sort($onDisk);

// Expected from routes
$expected = [];
$routeFiles = glob($root . '/routes/*.php') ?: [];
foreach ($routeFiles as $rf) {
    $content = file_get_contents($rf) ?: '';
    if (preg_match_all('/(?:use |\\\\)App\\\\([A-Za-z0-9_\\\\]+)/', $content, $m)) {
        foreach ($m[1] as $suffix) {
            $expected[] = str_replace('\\', '/', $suffix) . '.php';
        }
    }
}

// Expected from composer autoload files
$composer = json_decode(file_get_contents($root . '/composer.json') ?: '{}', true);
foreach ($composer['autoload']['files'] ?? [] as $f) {
    if (str_starts_with($f, 'app/')) {
        $expected[] = substr($f, 4);
    }
}

// Scan app/ for class references to other App\ classes
$scanDirs = [$root . '/app', $root . '/routes'];
$classPattern = '/(?:use App\\\\|\\\\App\\\\)([A-Za-z0-9_\\\\]+)/';
foreach ($scanDirs as $dir) {
    $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it2 as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $content = file_get_contents($file->getPathname()) ?: '';
        if (preg_match_all($classPattern, $content, $m)) {
            foreach ($m[1] as $suffix) {
                $expected[] = str_replace('\\', '/', $suffix) . '.php';
            }
        }
    }
}

$expected = array_unique($expected);
sort($expected);

$missing = [];
foreach ($expected as $rel) {
    $path = $appDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        $missing[] = $rel;
    }
}

// Tiny/suspicious files (< 50 bytes)
$tiny = [];
foreach ($onDisk as $rel) {
    if (!str_ends_with($rel, '.php')) continue;
    $path = $appDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (filesize($path) < 50) {
        $tiny[] = $rel . ' (' . filesize($path) . ' bytes)';
    }
}

echo "On disk: " . count($onDisk) . " files\n";
echo "Expected (referenced): " . count($expected) . " classes\n";
echo "Missing referenced: " . count($missing) . "\n";
foreach ($missing as $m) echo "  MISSING: $m\n";
echo "Tiny files (<50b): " . count($tiny) . "\n";
foreach ($tiny as $t) echo "  TINY: $t\n";
