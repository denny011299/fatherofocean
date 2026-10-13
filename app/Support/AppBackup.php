<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use ZipArchive;

final class AppBackup
{
    public const DEFAULT_KEEP_DAYS = 14;

    /** @return array{sql_zip: string, files_zip: ?string, env_file: string, pruned: int} */
    public function run(int $keepDays = self::DEFAULT_KEEP_DAYS): array
    {
        $backupDir = $this->backupDir();
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');
        $database = (string) config('database.connections.mysql.database');

        $sqlZip = $this->dumpDatabaseZip($backupDir, $database, $timestamp);
        $filesZip = $this->backupPublicFilesZip($backupDir, $timestamp);
        $envFile = $this->backupEnvFile($backupDir, $timestamp);

        return [
            'sql_zip' => $sqlZip,
            'files_zip' => $filesZip,
            'env_file' => $envFile,
            'pruned' => $this->pruneOldFiles($backupDir, $keepDays),
        ];
    }

    public function backupDir(): string
    {
        return storage_path('app/backups');
    }

    public function createDatabaseDownloadZip(): string
    {
        $backupDir = $this->backupDir();
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');
        $database = (string) config('database.connections.mysql.database');

        return $this->dumpDatabaseZip($backupDir, $database, $timestamp);
    }

    private function dumpDatabaseZip(string $backupDir, string $database, string $timestamp): string
    {
        $sqlFile = "{$backupDir}/{$database}_{$timestamp}.sql";
        $zipFile = "{$backupDir}/db_{$database}_{$timestamp}.zip";

        if (! $this->runMysqldump($sqlFile)) {
            $this->fallbackSqlExport($sqlFile, $database);
        }

        if (! is_file($sqlFile) || filesize($sqlFile) === 0) {
            throw new \RuntimeException('Dump database gagal: file SQL tidak dibuat.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('Ekstensi PHP ZipArchive tidak tersedia di server.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Tidak bisa membuat zip backup: {$zipFile}");
        }

        $zip->addFile($sqlFile, basename($sqlFile));
        $zip->close();

        if (! is_file($zipFile) || filesize($zipFile) === 0) {
            throw new \RuntimeException('Gagal membuat file ZIP backup database.');
        }

        if (is_file($sqlFile)) {
            @unlink($sqlFile);
        }

        return $zipFile;
    }

    private function backupPublicFilesZip(string $backupDir, string $timestamp): ?string
    {
        $publicRoot = storage_path('app/public');
        if (! is_dir($publicRoot)) {
            return null;
        }

        $zipFile = "{$backupDir}/files_{$timestamp}.zip";
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Tidak bisa membuat zip file upload: {$zipFile}");
        }

        $this->addDirectoryToZip($zip, $publicRoot, 'storage/app/public');
        $zip->close();

        return $zipFile;
    }

    private function backupEnvFile(string $backupDir, string $timestamp): string
    {
        $envPath = base_path('.env');
        $target = "{$backupDir}/env_{$timestamp}.env";

        if (! is_file($envPath)) {
            throw new \RuntimeException('.env tidak ditemukan.');
        }

        if (! copy($envPath, $target)) {
            throw new \RuntimeException("Gagal menyalin .env ke {$target}");
        }

        @chmod($target, 0600);

        return $target;
    }

    private function runMysqldump(string $sqlFile): bool
    {
        $database = (string) config('database.connections.mysql.database');
        $username = (string) config('database.connections.mysql.username');
        $password = (string) config('database.connections.mysql.password');
        $host = (string) config('database.connections.mysql.host');
        $port = (int) config('database.connections.mysql.port', 3306);

        if ($database === '' || $username === '') {
            return false;
        }

        $process = new Process([
            $this->resolveMysqldumpBinary(),
            '--host='.$host,
            '--port='.(string) $port,
            '--user='.$username,
            '--single-transaction',
            '--routines',
            '--triggers',
            $database,
        ]);

        if ($password !== '') {
            $process->setEnv(array_merge($process->getEnv(), ['MYSQL_PWD' => $password]));
        }

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        $written = file_put_contents($sqlFile, $process->getOutput());

        return $written !== false && is_file($sqlFile) && filesize($sqlFile) > 0;
    }

    private function resolveMysqldumpBinary(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $found = trim((string) shell_exec('where mysqldump.exe 2>NUL'));
            if ($found !== '') {
                return trim(strtok($found, PHP_EOL));
            }

            return 'mysqldump.exe';
        }

        return 'mysqldump';
    }

    private function fallbackSqlExport(string $sqlFile, string $database): void
    {
        $handle = fopen($sqlFile, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Tidak bisa menulis file SQL: {$sqlFile}");
        }

        fwrite($handle, "-- Database backup: {$database}\n");
        fwrite($handle, '-- Generated: '.now()->toDateTimeString()."\n");
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

            $rows = DB::table($table)->cursor();
            $columns = null;
            $colList = '';
            $values = [];
            $batchSize = 100;

            foreach ($rows as $row) {
                $rowArray = (array) $row;
                if ($columns === null) {
                    $columns = array_keys($rowArray);
                    $colList = implode('`, `', $columns);
                }

                $vals = array_map(static function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }

                    return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v)."'";
                }, array_values($rowArray));
                $values[] = '('.implode(', ', $vals).')';

                if (count($values) >= $batchSize) {
                    fwrite($handle, "INSERT INTO `{$table}` (`{$colList}`) VALUES\n".implode(",\n", $values).";\n\n");
                    $values = [];
                }
            }

            if ($values !== []) {
                fwrite($handle, "INSERT INTO `{$table}` (`{$colList}`) VALUES\n".implode(",\n", $values).";\n\n");
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    private function addDirectoryToZip(ZipArchive $zip, string $directory, string $zipPrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            $relative = $zipPrefix.'/'.ltrim(str_replace('\\', '/', substr($path, strlen($directory))), '/');

            if ($item->isDir()) {
                $zip->addEmptyDir(rtrim($relative, '/'));

                continue;
            }

            $zip->addFile($path, $relative);
        }
    }

    private function pruneOldFiles(string $backupDir, int $keepDays): int
    {
        $cutoff = now()->subDays(max(1, $keepDays))->getTimestamp();
        $deleted = 0;

        foreach (glob($backupDir.'/*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $basename = basename($path);
            if (! preg_match('/^(db_|files_|env_)/', $basename)) {
                continue;
            }

            if (filemtime($path) !== false && filemtime($path) < $cutoff) {
                if (@unlink($path)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
