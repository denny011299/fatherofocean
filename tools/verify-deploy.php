#!/usr/bin/env php
<?php

/**
 * Pre-deploy validator — run before uploading to production.
 *
 * Usage:
 *   php tools/verify-deploy.php
 *   php tools/verify-deploy.php --fix-report   (same checks, writes tools/verify-deploy-report.txt)
 *
 * Exit codes: 0 = pass, 1 = failures found
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$writeReport = in_array('--fix-report', $argv ?? [], true);
$errors = [];
$warnings = [];

function rel(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);

    return ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');
}

// ── 1. Syntax check (php -l) ─────────────────────────────────────────────
echo "Checking PHP syntax (app/)...\n";
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        $errors[] = '[syntax] ' . rel($path, $root) . ': ' . implode(' ', $output);
    }
}

// ── 2. Required classes must exist and be loadable ────────────────────
echo "Checking required classes...\n";
$requiredClasses = [
    'App\\Support\\Locales',
    'App\\Support\\MarkingPricingResolver',
    'App\\Support\\ShippingLabelPdfData',
    'App\\Support\\OmsetInvoiceQuery',
    'App\\Support\\OmsetExportDataBuilder',
    'App\\Http\\Controllers\\ExportController',
    'App\\Models\\Marking',
    'App\\Models\\Invoice',
    'App\\Models\\MasterPricingItem',
];

require $root . '/vendor/autoload.php';

foreach ($requiredClasses as $class) {
    if (! class_exists($class)) {
        $errors[] = "[class] Missing class: {$class}";
    }
}

// ── 3. Key public API methods (production crash guards) ─────────────────
echo "Checking key public methods...\n";
$keyMethods = [
    'App\\Support\\Locales' => ['name', 'names', 'available', 'isSupported', 'routePattern'],
    'App\\Support\\MarkingPricingResolver' => ['normalizeKey', 'legacyCbmTypeAlias', 'pickRow', 'modalRatesFromPricingRow'],
    'App\\Support\\ShippingLabelPdfData' => ['forBarang', 'filename'],
    'App\\Support\\OmsetInvoiceQuery' => ['applyPeriodFilter', 'applyBarangPeriodFilter', 'applyJoinedBarangPeriodFilter', 'omsetPeriodSelectSql', 'paymentStatusLabel', 'periodDateExpression', 'periodDateColumn'],
    'App\\Support\\OmsetExportDataBuilder' => ['build', 'buildPreview'],
    'App\\Http\\Controllers\\ExportController' => ['omsetIndex', 'omsetData', 'omsetExcel', 'omsetPdf'],
    'App\\Models\\Marking' => [
        'visibleToUser', 'restrictBarangsQuery', 'restrictInvoicesQuery', 'whereBarangMarkingValue',
        'userHasTeamMarkingScope', 'barangVisibleToUser', 'findForBarangValue', 'codeColumn',
        'lastShippingAtSubquery', 'hasColumnCached',
    ],
    'App\\Models\\Invoice' => ['fullyPaid'],
    'App\\Models\\User' => [
        'portalScopeUserIds',
        'subordinates',
        'earnsCapitalGainOnly',
        'commissionRateOverride',
        'commissionRateLabel',
    ],
    'App\\Models\\Barang' => [
        'hasCompleteProductList',
        'itemsSummary',
        'displayNoResi',
        'hasNoResi',
        'updateBarang',
        'insertBarang',
        'shipmentStatusLabel',
        'userCanSetDitarikKembali',
        'isContainerLoadable',
        'recordInvoiceIfBongkar',
        'projectedInvoiceSkipReasonOnBongkar',
        'backfillMissingInvoicesForBongkar',
        'syncEtdTracking',
        'syncEtaTracking',
        'productUploadFileKeys',
        'isEmptyProductInputRow',
        'deferAfterUpdate',
    ],
];

$methodExists = static function (string $class, string $method): bool {
    if (method_exists($class, $method)) {
        return true;
    }

    // Eloquent local scopes are defined as scope{Name}().
    return method_exists($class, 'scope'.ucfirst($method));
};

foreach ($keyMethods as $class => $methods) {
    if (! class_exists($class)) {
        continue;
    }
    foreach ($methods as $method) {
        if (! $methodExists($class, $method)) {
            $errors[] = "[method] {$class}::{$method}() missing";
        }
    }
}

$scanDirs = [$root . '/app', $root . '/resources/views', $root . '/routes'];

// ── 3b. Model::active() calls must have scopeActive() ───────────────────
echo "Checking Model::active() scopes...\n";
$activeCallPattern = '/(?:\\\\App\\\\Models\\\\|(?:^|[^\\\\\w])|use App\\\\Models\\\\)([A-Za-z0-9_]+)::active\s*\(/';
$activeModels = [];

foreach ($scanDirs as $dir) {
    if (! is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $ext = $file->getExtension();
        if ($ext !== 'php' && ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        $content = file_get_contents($file->getPathname()) ?: '';
        if (preg_match_all($activeCallPattern, $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $activeModels[$match[1]] = true;
            }
        }
    }
}

foreach (array_keys($activeModels) as $model) {
    $class = "App\\Models\\{$model}";
    if (! class_exists($class)) {
        $errors[] = "[scope] {$model}::active() referenced but {$class} missing";
        continue;
    }
    if (! method_exists($class, 'scopeActive')) {
        $errors[] = "[scope] {$class}::scopeActive() missing ({$model}::active() will fail)";
    }
}

if (class_exists('App\\Models\\User') && ! method_exists('App\\Models\\User', 'scopeMarketingStaff')) {
    $errors[] = '[scope] App\\Models\\User::scopeMarketingStaff() missing (User::marketingStaff() will fail)';
}

if (class_exists('App\\Models\\Barang') && ! method_exists('App\\Models\\Barang', 'scopeAwaitingContainerLoad')) {
    $errors[] = '[scope] App\\Models\\Barang::scopeAwaitingContainerLoad() missing (Barang::awaitingContainerLoad() will fail)';
}

if (class_exists('App\\Models\\Barang') && ! method_exists('App\\Models\\Barang', 'scopeEligibleForDeliveryTrip')) {
    $errors[] = '[scope] App\\Models\\Barang::scopeEligibleForDeliveryTrip() missing (Barang::eligibleForDeliveryTrip() will fail)';
}

if (class_exists('App\\Models\\Barang') && ! method_exists('App\\Models\\Barang', 'scopeFilterByPricingItemId')) {
    $errors[] = '[scope] App\\Models\\Barang::scopeFilterByPricingItemId() missing (Barang::filterByPricingItemId() will fail)';
}

if (class_exists('App\\Models\\Barang') && ! method_exists('App\\Models\\Barang', 'scopePendingBongkarInvoiceBackfill')) {
    $errors[] = '[scope] App\\Models\\Barang::scopePendingBongkarInvoiceBackfill() missing (Barang::pendingBongkarInvoiceBackfill() will fail)';
}

// ── 4. Scan app/ for static calls to Support classes ────────────────────
echo "Scanning static method references...\n";
$staticCallPattern = '/(?:\\\\App\\\\Support\\\\|App\\\\Support\\\\|use App\\\\Support\\\\)([A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/';
$foundCalls = [];

foreach ($scanDirs as $dir) {
    if (! is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $ext = $file->getExtension();
        if (! in_array($ext, ['php', 'blade.php'], true) && ! str_ends_with($file->getFilename(), '.blade.php')) {
            if ($ext !== 'php') {
                continue;
            }
        }
        $content = file_get_contents($file->getPathname()) ?: '';
        if (preg_match_all($staticCallPattern, $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $foundCalls["App\\Support\\{$match[1]}::{$match[2]}"] = true;
            }
        }
        if (preg_match_all('/\\\\App\\\\Support\\\\([A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $content, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $match) {
                $foundCalls["App\\Support\\{$match[1]}::{$match[2]}"] = true;
            }
        }
    }
}

foreach (array_keys($foundCalls) as $ref) {
    [$class, $method] = explode('::', $ref);
    if (! class_exists($class)) {
        $errors[] = "[ref] Referenced class missing: {$class} (via {$method}())";
        continue;
    }
    if (! method_exists($class, $method)) {
        $errors[] = "[ref] {$class}::{$method}() referenced but not defined";
    }
}

// ── 5. Namespace sanity for ShippingLabelPdfData ────────────────────────
$shippingLabelFile = $root . '/app/Support/ShippingLabelPdfData.php';
if (is_file($shippingLabelFile)) {
    $src = file_get_contents($shippingLabelFile) ?: '';
    if (! str_contains($src, 'namespace App\\Support;')) {
        $errors[] = '[namespace] ShippingLabelPdfData.php must use namespace App\\Support';
    }
    if (! str_contains($src, 'final class ShippingLabelPdfData')) {
        $errors[] = '[namespace] ShippingLabelPdfData.php missing final class ShippingLabelPdfData';
    }
}

// ── Report ──────────────────────────────────────────────────────────────
echo "\n";
if ($errors === []) {
    echo "PASS — all checks OK (" . count($foundCalls) . " Support static refs verified)\n";
    exit(0);
}

echo "FAIL — " . count($errors) . " error(s):\n";
foreach ($errors as $err) {
    echo "  • {$err}\n";
}

if ($writeReport) {
    $report = $root . '/tools/verify-deploy-report.txt';
    file_put_contents($report, implode(PHP_EOL, $errors) . PHP_EOL);
    echo "\nReport written to tools/verify-deploy-report.txt\n";
}

exit(1);
