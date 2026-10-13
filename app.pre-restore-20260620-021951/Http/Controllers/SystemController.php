<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

final class SystemController extends Controller
{
    public function index(): View
    {
        return view('system.index', [
            'appLogoUrl' => Branding::logoUrl(),
        ]);
    }

    public function activityIndex(Request $request): View
    {
        $logs = ActivityLog::with('user')
            ->when($request->get('action'), fn ($q, $a) => $q->where('action', 'like', "%{$a}%"))
            ->when($request->get('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->latest()
            ->paginate(30);

        return view('system.activity', compact('logs'));
    }

    public function exportDatabase(): BinaryFileResponse
    {
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port', 3306);

        $timestamp = now()->format('Y-m-d_His');
        $backupDir = storage_path('app/backups');
        $sqlFile = "{$backupDir}/{$database}_{$timestamp}.sql";
        $zipFile = "{$backupDir}/{$database}_{$timestamp}.zip";

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $passFlag = $password ? "-p\"{$password}\"" : '';
        $cmd = "mysqldump -h {$host} -P {$port} -u {$username} {$passFlag} {$database} > \"{$sqlFile}\" 2>&1";

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($sqlFile)) {
            $this->fallbackExport($sqlFile, $database);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFile($sqlFile, basename($sqlFile));
            $zip->close();
        }

        if (file_exists($sqlFile)) {
            @unlink($sqlFile);
        }

        ActivityLog::record('Database exported', null, null, ['file' => basename($zipFile)]);

        return response()->download($zipFile, basename($zipFile))->deleteFileAfterSend(false);
    }

    public function shutdown(Request $request): JsonResponse
    {
        $request->validate([
            'secret' => 'required|string',
        ]);

        ActivityLog::record('Emergency shutdown triggered');

        Artisan::call('down', [
            '--secret' => $request->secret,
        ]);

        return response()->json(['success' => true, 'message' => 'Sistem dalam mode maintenance. Bypass: /' . $request->secret]);
    }

    public function bringUp(): JsonResponse
    {
        Artisan::call('up');
        ActivityLog::record('System brought back online');

        return response()->json(['success' => true, 'message' => 'Sistem kembali online.']);
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        Branding::storeLogo($request->file('logo'));
        ActivityLog::record('App logo updated');

        return redirect()
            ->route('system.index')
            ->with('status', 'Logo berhasil diunggah.');
    }

    public function removeLogo(): RedirectResponse
    {
        Branding::removeLogo();
        ActivityLog::record('App logo removed');

        return redirect()
            ->route('system.index')
            ->with('status', 'Logo dikembalikan ke default.');
    }

    private function fallbackExport(string $sqlFile, string $database): void
    {
        $handle = fopen($sqlFile, 'w');
        fwrite($handle, "-- Database backup: {$database}\n");
        fwrite($handle, "-- Generated: " . now()->toDateTimeString() . "\n");
        fwrite($handle, "-- Method: PHP fallback (mysqldump unavailable)\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = DB::select('SHOW TABLES');
        $dbKey = "Tables_in_{$database}";

        foreach ($tables as $tableObj) {
            $table = $tableObj->$dbKey;

            $createResult = DB::select("SHOW CREATE TABLE `{$table}`");
            $createSql = $createResult[0]->{'Create Table'} ?? '';

            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, "{$createSql};\n\n");

            $rows = DB::table($table)->get();
            if ($rows->isEmpty()) continue;

            $columns = array_keys((array) $rows->first());
            $colList = implode('`, `', $columns);

            foreach ($rows->chunk(100) as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    $vals = array_map(function ($v) {
                        if ($v === null) return 'NULL';
                        return "'" . addslashes((string) $v) . "'";
                    }, array_values((array) $row));
                    $values[] = '(' . implode(', ', $vals) . ')';
                }
                fwrite($handle, "INSERT INTO `{$table}` (`{$colList}`) VALUES\n" . implode(",\n", $values) . ";\n\n");
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }
}
