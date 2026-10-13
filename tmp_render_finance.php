<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\Gate::before(static fn () => true);

$user = App\Models\User::make([
    'id' => 1,
    'name' => 'Test Admin',
    'username' => 'admin',
]);
$user->id = 1;
auth()->setUser($user);

$html = view('finance.index', [
    'invoices' => collect([]),
    'customers' => collect([]),
    'stats' => ['total' => 0, 'unpaid' => 0, 'overdue' => 0, 'paid' => 0],
    'activeCard' => null,
])->render();

file_put_contents(__DIR__ . '/tmp_finance_render.html', $html);

echo 'LEN=' . strlen($html) . PHP_EOL;
if (preg_match('/<link[^>]+app-[^"]+\.css/', $html, $m)) {
    echo 'CSS: ' . $m[0] . PHP_EOL;
} else {
    echo 'NO CSS LINK' . PHP_EOL;
}

$scriptOpen = substr_count($html, '<script');
$scriptClose = substr_count($html, '</script>');
echo "scripts={$scriptOpen} close={$scriptClose}" . PHP_EOL;

if ($scriptOpen !== $scriptClose) {
    echo "MISMATCH SCRIPT TAGS" . PHP_EOL;
}

$pos = 0;
while (($s = strpos($html, '<script', $pos)) !== false) {
    $e = strpos($html, '</script>', $s);
    if ($e === false) {
        echo "UNCLOSED SCRIPT at {$s}" . PHP_EOL;
        break;
    }
    $block = substr($html, $s, $e - $s);
    if (str_contains($block, '</p>')) {
        echo "Script at {$s} contains literal </p>" . PHP_EOL;
    }
    $pos = $e + 9;
}

echo 'DONE' . PHP_EOL;
