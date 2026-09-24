<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RuntimeException;

class BackupService
{
    public function status(): array
    {
        return [
            'available' => config('database.default') === 'mysql' && class_exists(PharData::class) && extension_loaded('zlib'),
            'database_engine' => config('database.default'),
            'format_version' => config('backup.format_version'),
            'destination' => config('backup.disk'),
            'retention_count' => config('backup.retention_count'),
            'schedule' => config('backup.schedule'),
            'latest_successful' => $this->list()->first(),
        ];
    }

    public function list()
    {
        return DB::table('backup_runs')->latest('id')->get()->map(fn ($run) => $this->present($run));
    }

    public function create(int $userId): array
    {
        $result = Cache::lock('system-backup-create', 300)->get(
            fn () => $this->createUnlocked($userId)
        );

        if ($result === null) {
            throw new RuntimeException('Another backup is already running.');
        }

        return $result;
    }

    private function createUnlocked(int $userId): array
    {
        $runId = DB::table('backup_runs')->insertGetId([
            'requested_by' => $userId, 'status' => 'running', 'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stage = storage_path('framework/cache/backup-'.Str::uuid());
        try {
            if (config('database.default') !== 'mysql') {
                throw new RuntimeException('Production backups require the configured MySQL connection.');
            }
            if (! class_exists(PharData::class) || ! extension_loaded('zlib')) {
                throw new RuntimeException('PHP Phar and zlib support are required.');
            }
            mkdir($stage, 0750, true);
            $dump = $stage.'/database.sql';
            $this->dumpDatabase($dump);
            $manifest = $this->buildManifest($stage);
            file_put_contents($stage.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $name = 'backup-'.now()->format('Ymd-His').'-'.$runId.'.tar.gz';
            $package = $stage.'/'.$name;
            $this->archiveDirectory($stage, $package);
            $reference = trim(config('backup.prefix'), '/').'/'.$name;
            $stream = fopen($package, 'rb');
            $stored = Storage::disk(config('backup.disk'))->put($reference, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! $stored) {
                throw new RuntimeException('Backup package could not be written to the configured disk.');
            }
            DB::table('backup_runs')->where('id', $runId)->update([
                'status' => 'completed', 'completed_at' => now(), 'size' => filesize($package),
                'storage_reference' => $reference, 'checksum' => hash_file('sha256', $package), 'updated_at' => now(),
            ]);
            $this->audit($userId, 'backup_created', $runId, ['backup_id' => $runId]);
            $this->applyRetention();

            return $this->present(DB::table('backup_runs')->find($runId));
        } catch (\Throwable $e) {
            DB::table('backup_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error' => Str::limit($this->safeError($e->getMessage()), 1000), 'updated_at' => now(),
            ]);
            $this->audit($userId, 'backup_failed', $runId, ['backup_id' => $runId]);
            throw $e;
        } finally {
            $this->removeDirectory($stage);
        }
    }

    public function validate(int $id, ?int $userId = null): array
    {
        $run = DB::table('backup_runs')->find($id);
        if (! $run || $run->status !== 'completed' || ! $run->storage_reference) {
            throw new RuntimeException('Backup is not available for validation.');
        }
        $disk = Storage::disk(config('backup.disk'));
        if (! $disk->exists($run->storage_reference)) {
            throw new RuntimeException('Backup package is missing.');
        }
        $temp = tempnam(storage_path('framework/cache'), 'backup-validate-').'.tar.gz';
        file_put_contents($temp, $disk->get($run->storage_reference));
        try {
            if (! hash_equals((string) $run->checksum, hash_file('sha256', $temp))) {
                throw new RuntimeException('Backup package checksum failed.');
            }
            try {
                $archive = new PharData($temp);
            } catch (\Throwable) {
                throw new RuntimeException('Backup package is not a valid TAR/GZIP archive.');
            }
            if (! isset($archive['manifest.json']) || ($manifest = json_decode($archive['manifest.json']->getContent(), true)) === null) {
                throw new RuntimeException('Backup manifest is missing or invalid.');
            }
            if (($manifest['format_version'] ?? null) !== config('backup.format_version')) {
                throw new RuntimeException('Backup format is incompatible.');
            }
            if (($manifest['database_engine'] ?? null) !== 'mysql') {
                throw new RuntimeException('Backup database engine is incompatible.');
            }
            foreach ($manifest['files'] ?? [] as $file) {
                if (! isset($archive[$file['path']])) {
                    throw new RuntimeException('Backup file integrity failed.');
                }
                $content = $archive[$file['path']]->getContent();
                if (strlen($content) !== $file['size'] || hash('sha256', $content) !== $file['sha256']) {
                    throw new RuntimeException('Backup file integrity failed.');
                }
            }
            if (! isset($archive['database.sql'])) {
                throw new RuntimeException('Database dump is missing.');
            }
            $sql = $archive['database.sql']->getContent();
            if (! preg_match('/\b(CREATE\s+TABLE|INSERT\s+INTO|MySQL dump)\b/i', $sql)) {
                throw new RuntimeException('Database dump content is not recognizable.');
            }
            if ($userId) {
                $this->audit($userId, 'backup_validated', $id, ['backup_id' => $id]);
            }

            return ['valid' => true, 'backup_id' => $id, 'manifest' => $manifest];
        } finally {
            @unlink($temp);
        }
    }

    public function prepareRestore(int $id, string $confirmation, int $userId): array
    {
        if ($confirmation !== 'RESTORE') {
            throw new RuntimeException('Typed confirmation RESTORE is required.');
        }
        $validation = $this->validate($id);
        $this->audit($userId, 'restore_prepared', $id, ['backup_id' => $id]);

        return ['status' => 'validated', 'backup_id' => $id, 'destructive_restore_executed' => false, 'manifest' => $validation['manifest'], 'command' => 'php artisan backup:restore '.$id.' --target-database=RECOVERY_DATABASE --storage-path=RECOVERY_STORAGE --confirm=RESTORE'];
    }

    public function artifact(int $id): object
    {
        $run = DB::table('backup_runs')->find($id);
        if (! $run || $run->status !== 'completed' || ! $run->storage_reference) {
            abort(404);
        }

        return $run;
    }

    public function recordDownload(int $id, int $userId): void
    {
        $this->audit($userId, 'backup_downloaded', $id, ['backup_id' => $id]);
    }

    private function dumpDatabase(string $path): void
    {
        $db = config('database.connections.mysql');
        $handle = fopen($path, 'wb');
        $result = Process::env($this->databaseProcessEnvironment((string) $db['password']))->forever()->run([
            config('backup.mysqldump_binary'), '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--no-tablespaces',
            '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'], $db['database'],
        ], function ($type, $output) use ($handle) {
            if ($type === 'out') {
                fwrite($handle, $output);
            }
        });
        fclose($handle);
        if (! $result->successful() || ! filesize($path)) {
            throw new RuntimeException('Database backup command failed (exit '.$result->exitCode().'): '.$this->safeError($result->errorOutput()));
        }
    }

    private function databaseProcessEnvironment(string $password): array
    {
        $environment = ['MYSQL_PWD' => $password];

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP'] as $name) {
                $value = getenv($name);
                if ($value !== false && $value !== '') {
                    $environment[$name] = $value;
                }
            }
        }

        return $environment;
    }

    private function buildManifest(string $stage): array
    {
        $files = [['path' => 'database.sql', 'size' => filesize($stage.'/database.sql'), 'sha256' => hash_file('sha256', $stage.'/database.sql')]];
        foreach (config('backup.include_disks') as $diskName) {
            $disk = Storage::disk($diskName);
            foreach ($disk->allFiles() as $key) {
                if ($diskName === config('backup.disk') && str_starts_with($key, trim(config('backup.prefix'), '/').'/')) {
                    continue;
                }
                $target = 'storage/'.$diskName.'/'.$key;
                $targetPath = $stage.'/'.$target;
                if (! is_dir(dirname($targetPath))) {
                    mkdir(dirname($targetPath), 0750, true);
                }
                file_put_contents($targetPath, $disk->get($key));
                $files[] = ['path' => $target, 'size' => filesize($targetPath), 'sha256' => hash_file('sha256', $targetPath)];
            }
        }

        return ['created_at' => now()->toISOString(), 'app_version' => config('app.version', 'unknown'), 'database_engine' => 'mysql', 'format_version' => config('backup.format_version'), 'included_components' => ['database', 'private_storage', 'public_uploads'], 'files' => $files];
    }

    private function archiveDirectory(string $root, string $output): void
    {
        $tarPath = substr($output, 0, -3);
        $archive = new PharData($tarPath);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->getPathname() !== $tarPath) {
                $archive->addFile($file->getPathname(), $relative);
            }
        }
        $archive->compress(Phar::GZ);
        unset($archive);
        @unlink($tarPath);
    }

    private function applyRetention(): void
    {
        $runs = DB::table('backup_runs')->where('status', 'completed')->latest('id')->get();
        foreach ($runs->slice(config('backup.retention_count')) as $run) {
            Storage::disk(config('backup.disk'))->delete($run->storage_reference);
            DB::table('backup_runs')->where('id', $run->id)->update(['status' => 'pruned', 'storage_reference' => null, 'updated_at' => now()]);
        }
    }

    private function present(object $run): array
    {
        $validatedAt = DB::table('audit_logs')->where('record_id', $run->id)->where('table_name', 'backup_runs')->where('action_type', 'backup_validated')->max('created_at');

        return ['id' => $run->id, 'status' => $run->status, 'created_at' => $run->requested_at, 'completed_at' => $run->completed_at, 'size' => $run->size, 'checksum' => $run->checksum, 'integrity_status' => $validatedAt ? 'valid' : ($run->checksum ? 'not_checked' : 'unavailable'), 'last_validated_at' => $validatedAt, 'included_components' => $run->status === 'completed' ? ['database', 'private_storage', 'public_uploads'] : [], 'error' => $run->status === 'failed' ? $run->error : null];
    }

    private function audit(int $userId, string $action, int $id, array $values): void
    {
        DB::table('audit_logs')->insert(['user_id' => $userId, 'table_name' => 'backup_runs', 'model_name' => self::class, 'record_id' => $id, 'action' => $action, 'action_type' => $action, 'new_data' => json_encode($values), 'new_values' => json_encode($values), 'created_at' => now()]);
    }

    private function safeError(string $message): string
    {
        $password = (string) config('database.connections.mysql.password');

        return trim(str_replace(array_filter([$password, storage_path(), base_path()]), '[redacted]', $message));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
