<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Phar;
use PharData;
use Tests\TestCase;

class BackupRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        config(['backup.disk' => 'local', 'backup.prefix' => 'system-backups']);
        $role = DB::table('roles')->where('name', 'Administrator')->value('id');
        $this->admin = User::factory()->create(['role' => $role, 'force_change_password' => false]);
    }

    public function test_only_authorized_administrators_can_access_recovery_endpoints(): void
    {
        $user = User::factory()->create(['force_change_password' => false]);
        $this->actingAs($user, 'sanctum')->getJson('/api/system/backups')->assertForbidden();
        $this->postJson('/api/system/backups')->assertForbidden();
        $this->postJson('/api/system/backups/1/restore', ['confirmation' => 'RESTORE'])->assertForbidden();
    }

    public function test_administrator_can_initiate_backup_without_exposing_paths_or_secrets(): void
    {
        $this->mock(BackupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->with($this->admin->id)->andReturn(['id' => 7, 'status' => 'completed', 'checksum' => str_repeat('a', 64)]);
        });
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/system/backups')->assertCreated()->assertJsonPath('data.status', 'completed')->assertJsonMissingPath('data.storage_reference')->assertDontSee('DB_PASSWORD');
    }

    public function test_failed_backup_is_marked_failed_and_never_becomes_downloadable(): void
    {
        try {
            app(BackupService::class)->create($this->admin->id);
            $this->fail('SQLite test environment must not fabricate a production backup.');
        } catch (\RuntimeException) {
            $run = DB::table('backup_runs')->latest('id')->first();
            $this->assertSame('failed', $run->status);
            $this->assertNull($run->storage_reference);
            $this->actingAs($this->admin, 'sanctum')->get("/api/system/backups/$run->id/download")->assertNotFound();
        }
    }

    public function test_concurrent_backup_request_is_rejected_before_a_run_is_created(): void
    {
        $lock = Cache::lock('system-backup-create', 300);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/system/backups')
                ->assertServerError()
                ->assertJsonPath('message', __('backup.creation_failed'));

            $this->assertDatabaseCount('backup_runs', 0);
        } finally {
            $lock->release();
        }
    }

    public function test_manifest_assets_checksums_confirmation_and_audit_are_enforced(): void
    {
        $id = $this->storeValidPackage();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/system/backups')->assertOk()->assertJsonMissingPath('data.0.storage_reference');
        $this->postJson("/api/system/backups/$id/validate")->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.manifest.included_components.1', 'private_storage');
        $this->postJson("/api/system/backups/$id/restore", ['confirmation' => 'wrong'])->assertUnprocessable();
        $this->postJson("/api/system/backups/$id/restore", ['confirmation' => 'RESTORE'])->assertOk()->assertJsonPath('data.destructive_restore_executed', false)->assertJsonPath('data.status', 'validated');
        $this->assertDatabaseHas('audit_logs', ['record_id' => $id, 'action_type' => 'backup_validated']);
        $this->assertDatabaseHas('audit_logs', ['record_id' => $id, 'action_type' => 'restore_prepared']);
    }

    public function test_corrupted_or_failed_backup_cannot_enter_restore_workflow(): void
    {
        $id = $this->storeValidPackage();
        Storage::disk('local')->put("system-backups/backup-$id.tar.gz", 'corrupted');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/system/backups/$id/validate")->assertServerError();
        DB::table('backup_runs')->where('id', $id)->update(['status' => 'failed']);
        $this->postJson("/api/system/backups/$id/restore", ['confirmation' => 'RESTORE'])->assertServerError();
    }

    public function test_retention_keeps_the_newest_successful_package(): void
    {
        config(['backup.retention_count' => 1]);
        $old = $this->storeValidPackage();
        $new = $this->storeValidPackage();
        $method = new \ReflectionMethod(BackupService::class, 'applyRetention');
        $method->invoke(app(BackupService::class));
        $this->assertSame('pruned', DB::table('backup_runs')->find($old)->status);
        $this->assertSame('completed', DB::table('backup_runs')->find($new)->status);
        $this->assertTrue(Storage::disk('local')->exists("system-backups/backup-$new.tar.gz"));
    }

    private function storeValidPackage(): int
    {
        $id = DB::table('backup_runs')->insertGetId(['requested_by' => $this->admin->id, 'status' => 'running', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $temp = tempnam(storage_path('framework/cache'), 'backup-test-').'.tar';
        $database = "CREATE TABLE recovery_probe (id BIGINT);\n";
        $asset = 'private-logo';
        $manifest = ['created_at' => now()->toISOString(), 'app_version' => 'test', 'database_engine' => 'mysql', 'format_version' => 1, 'included_components' => ['database', 'private_storage'], 'files' => [
            ['path' => 'database.sql', 'size' => strlen($database), 'sha256' => hash('sha256', $database)],
            ['path' => 'storage/local/print-identity/logo.png', 'size' => strlen($asset), 'sha256' => hash('sha256', $asset)],
        ]];
        $archive = new PharData($temp);
        $archive->addFromString('database.sql', $database);
        $archive->addFromString('storage/local/print-identity/logo.png', $asset);
        $archive->addFromString('manifest.json', json_encode($manifest));
        $archive->compress(Phar::GZ);
        unset($archive);
        $package = $temp.'.gz';
        $reference = "system-backups/backup-$id.tar.gz";
        Storage::disk('local')->put($reference, file_get_contents($package));
        DB::table('backup_runs')->where('id', $id)->update(['status' => 'completed', 'completed_at' => now(), 'size' => filesize($package), 'storage_reference' => $reference, 'checksum' => hash_file('sha256', $package), 'updated_at' => now()]);
        unlink($temp);
        unlink($package);

        return $id;
    }
}
