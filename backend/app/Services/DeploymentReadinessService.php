<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

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
            return ['name' => 'database', 'status' => 'fail', 'message' => $exception->getMessage()];
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
        $backupPath = storage_path('app/backups');
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0750, true);
        }

        return [
            'name' => 'backup_directory',
            'status' => is_writable($backupPath) ? 'pass' : 'fail',
            'message' => is_writable($backupPath) ? 'Backup directory is writable.' : 'Backup directory is not writable.',
        ];
    }
}
