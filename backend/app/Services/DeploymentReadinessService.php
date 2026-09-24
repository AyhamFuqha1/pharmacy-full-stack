<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;

class DeploymentReadinessService
{
    public function report(): array
    {
        $checks = [];

        $checks[] = $this->checkAppKey();
        $checks[] = $this->checkDatabaseConnectivity();
        $checks[] = $this->checkStorageWriteability();
        $checks[] = $this->checkQueueDriver();
        $checks[] = $this->checkBackupDirectory();
        $checks[] = $this->checkExecutable('mysqldump', (string) config('backup.mysqldump_binary'));
        $checks[] = $this->checkExecutable('mysql_client', (string) config('backup.mysql_binary'));

        $failures = collect($checks)->where('status', 'fail')->count();
        $warnings = collect($checks)->where('status', 'warn')->count();

        return [
            'status' => $failures > 0 ? 'fail' : ($warnings > 0 ? 'warn' : 'pass'),
            'summary' => [
                'total_checks' => count($checks),
                'passed' => collect($checks)->where('status', 'pass')->count(),
                'warnings' => $warnings,
                'failures' => $failures,
            ],
            'checks' => $checks,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function checkAppKey(): array
    {
        return [
            'name' => 'app_key',
            'status' => filled(config('app.key')) ? 'pass' : 'fail',
            'message' => filled(config('app.key')) ? 'APP_KEY is configured.' : 'APP_KEY is missing.',
        ];
    }

    private function checkDatabaseConnectivity(): array
    {
        try {
            DB::select('select 1');

            return ['name' => 'database', 'status' => 'pass', 'message' => 'Database connection is healthy.'];
        } catch (\Throwable $exception) {
            report($exception);

            return ['name' => 'database', 'status' => 'fail', 'message' => 'Database connectivity check failed.'];
        }
    }

    private function checkStorageWriteability(): array
    {
        $storagePath = storage_path('app');
        $status = is_dir($storagePath) && is_writable($storagePath);

        return [
            'name' => 'storage_write',
            'status' => $status ? 'pass' : 'fail',
            'message' => $status ? 'Storage path is writable.' : 'Storage path is not writable.',
        ];
    }

    private function checkQueueDriver(): array
    {
        $driver = (string) config('queue.default');

        return [
            'name' => 'queue_driver',
            'status' => $driver === 'sync' ? 'warn' : 'pass',
            'message' => $driver === 'sync'
                ? 'Queue driver is sync; use async driver for production workloads.'
                : "Queue driver is {$driver}.",
        ];
    }

    private function checkBackupDirectory(): array
    {
        $disk = Storage::disk(config('backup.disk'));
        $probe = trim(config('backup.prefix'), '/').'/.readiness-'.Str::uuid();
        try {
            $status = $disk->put($probe, 'readiness') && $disk->exists($probe);
        } catch (\Throwable $exception) {
            report($exception);
            $status = false;
        } finally {
            try {
                $disk->delete($probe);
            } catch (\Throwable) {
            }
        }

        return [
            'name' => 'backup_directory',
            'status' => $status ? 'pass' : 'fail',
            'message' => $status ? 'Backup destination is writable.' : 'Backup destination is not writable.',
        ];
    }

    private function checkExecutable(string $name, string $binary): array
    {
        $available = (new ExecutableFinder)->find($binary) !== null;

        return ['name' => $name, 'status' => $available ? 'pass' : 'fail', 'message' => $available ? "$name is available." : "$name is not available."];
    }
}
