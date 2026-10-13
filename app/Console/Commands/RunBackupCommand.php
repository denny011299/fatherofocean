<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Support\AppBackup;
use Illuminate\Console\Command;

final class RunBackupCommand extends Command
{
    protected $signature = 'backup:run {--keep='.AppBackup::DEFAULT_KEEP_DAYS.' : Hari retensi file backup}';

    protected $description = 'Backup database + file upload ke storage/app/backups';

    public function handle(AppBackup $backup): int
    {
        $keepDays = max(1, (int) $this->option('keep'));

        try {
            $result = $backup->run($keepDays);
        } catch (\Throwable $e) {
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup selesai → '.$backup->backupDir());
        $this->line('SQL: '.basename($result['sql_zip']));
        if ($result['files_zip'] !== null) {
            $this->line('File: '.basename($result['files_zip']));
        }
        $this->line('Env: '.basename($result['env_file']));
        $this->line("File lama dihapus: {$result['pruned']} (retensi {$keepDays} hari)");

        ActivityLog::record('Scheduled backup completed', null, null, [
            'sql_zip' => basename($result['sql_zip']),
            'files_zip' => $result['files_zip'] !== null ? basename($result['files_zip']) : null,
            'pruned' => $result['pruned'],
        ]);

        return self::SUCCESS;
    }
}
