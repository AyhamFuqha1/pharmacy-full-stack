<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--path= : Destination SQL file}';
    protected $description = 'Create a database backup using configured database credentials.';

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/backups/backup-' . now()->format('Ymd-His') . '.sql');
        if (config('database.default') !== 'mysql') {
            $this->error('The backup command currently supports MySQL connections.');
            return self::FAILURE;
        }
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
        $result = Process::env(['MYSQL_PWD' => (string) config('database.connections.mysql.password')])->run([
            'mysqldump', '--host=' . config('database.connections.mysql.host'), '--port=' . config('database.connections.mysql.port'),
            '--user=' . config('database.connections.mysql.username'), config('database.connections.mysql.database'),
        ]);
        if (!$result->successful()) { $this->error($result->errorOutput()); return self::FAILURE; }
        file_put_contents($path, $result->output());
        $this->info("Backup written to {$path}");
        return self::SUCCESS;
    }
}