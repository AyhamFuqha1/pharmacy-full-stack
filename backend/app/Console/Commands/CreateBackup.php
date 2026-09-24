<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateBackup extends Command
{
    protected $signature = 'backup:create {--user= : Administrator user ID for audit attribution}';

    protected $description = 'Create a versioned database and storage recovery package.';

    public function handle(BackupService $backups): int
    {
        $user = (int) ($this->option('user') ?: DB::table('users')->where('role', DB::table('roles')->where('name', 'Administrator')->value('id'))->value('id'));
        if (! $user) {
            $this->error('An Administrator user is required for audit attribution.');

            return self::FAILURE;
        }
        try {
            $result = $backups->create($user);
            $this->info('Backup completed: #'.$result['id']);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
