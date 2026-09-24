<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PharData;

class RestoreBackup extends Command
{
    protected $signature = 'backup:restore {backup} {--target-database=} {--storage-path=} {--confirm=}';

    protected $description = 'Restore a validated backup into an isolated database and storage directory.';

    public function handle(BackupService $backups): int
    {
        if ($this->option('confirm') !== 'RESTORE') {
            $this->error('Use --confirm=RESTORE.');

            return self::FAILURE;
        }
        $target = trim((string) $this->option('target-database'));
        $storage = trim((string) $this->option('storage-path'));
        if (! $target || ! $storage || $target === config('database.connections.mysql.database')) {
            $this->error('An isolated target database and storage path are required. The active database is rejected.');

            return self::FAILURE;
        }
        $resolved = realpath($storage) ?: $storage;
        if (str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', storage_path('app')))) {
            $this->error('Recovery storage must not be inside active application storage.');

            return self::FAILURE;
        }
        $id = (int) $this->argument('backup');
        try {
            $backups->validate($id);
            $run = $backups->artifact($id);
            $package = tempnam(storage_path('framework/cache'), 'restore-').'.tar.gz';
            file_put_contents($package, Storage::disk(config('backup.disk'))->get($run->storage_reference));
            $extract = dirname($package).'/restore-'.uniqid();
            mkdir($extract, 0750, true);
            $archive = new PharData($package);
            $archive->extractTo($extract);
            $db = config('database.connections.mysql');
            $result = Process::env(['MYSQL_PWD' => (string) $db['password']])->input(file_get_contents($extract.'/database.sql'))->forever()->run([config('backup.mysql_binary'), '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'], $target]);
            if (! $result->successful()) {
                throw new \RuntimeException('Database import failed.');
            }
            if (! is_dir($storage)) {
                mkdir($storage, 0750, true);
            }
            $source = $extract.'/storage';
            if (is_dir($source)) {
                $this->copyDirectory($source, $storage);
            }
            $this->info('Isolated restore completed. Validate it before any production cutover.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $dest = $target.'/'.str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
            if ($item->isDir()) {
                if (! is_dir($dest)) {
                    mkdir($dest, 0750, true);
                }
            } else {
                if (! is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0750, true);
                } copy($item->getPathname(), $dest);
            }
        }
    }
}
