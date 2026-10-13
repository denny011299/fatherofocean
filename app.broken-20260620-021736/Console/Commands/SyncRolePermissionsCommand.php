<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;

final class SyncRolePermissionsCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Re-seed permissions and grant super_admin/supervisor every permission (run after deploy)';

    public function handle(): int
    {
        $this->info('Syncing permissions and full-access roles…');

        $this->call('db:seed', [
            '--class' => RolePermissionSeeder::class,
            '--force' => true,
        ]);

        $this->info('Done. Permission cache cleared.');

        return self::SUCCESS;
    }
}
