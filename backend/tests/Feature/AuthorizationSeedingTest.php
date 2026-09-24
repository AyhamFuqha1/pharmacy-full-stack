<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthorizationSeedingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_seeded_authorization_state_matches_backend_checks(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(AuthorizationSeeder::ROLE_PERMISSIONS),
            Role::query()->pluck('name')->all()
        );

        $referencedPermissions = collect(Route::getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($middleware) => str_starts_with($middleware, 'permission:'))
            ->flatMap(fn ($middleware) => explode(',', substr($middleware, strlen('permission:'))))
            ->merge($this->permissionsReferencedInApplicationCode())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(AuthorizationSeeder::PERMISSIONS, $referencedPermissions);
        $this->assertEqualsCanonicalizing(
            AuthorizationSeeder::PERMISSIONS,
            Permission::query()->pluck('name')->all()
        );

        foreach (AuthorizationSeeder::ROLE_PERMISSIONS as $roleName => $expected) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            $expected = $expected === '*' ? AuthorizationSeeder::PERMISSIONS : $expected;
            $this->assertEqualsCanonicalizing($expected, $role->permissions()->pluck('name')->all());
        }
    }

    public function test_first_admin_command_and_user_endpoint_resolve_all_permissions(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'First Admin',
            '--email' => 'first-admin@example.test',
            '--password' => 'StrongPass123!',
            '--password_confirmation' => 'StrongPass123!',
        ])->assertSuccessful();

        $admin = User::query()->where('email', 'first-admin@example.test')->firstOrFail();
        $administratorRole = Role::query()->where('name', 'Administrator')->firstOrFail();
        $this->assertSame($administratorRole->id, (int) $admin->role);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.role.name', 'Administrator')
            ->assertJsonPath('data.permissions', collect(AuthorizationSeeder::PERMISSIONS)->sort()->values()->all());

        $this->artisan('app:create-admin', [
            '--name' => 'Second Admin',
            '--email' => 'second-admin@example.test',
            '--password' => 'StrongPass123!',
            '--password_confirmation' => 'StrongPass123!',
        ])->assertFailed();
    }

    public function test_authorization_seeder_is_idempotent(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $this->seed(AuthorizationSeeder::class);

        $this->assertSame(count(AuthorizationSeeder::PERMISSIONS), Permission::query()->count());
        $this->assertSame(count(AuthorizationSeeder::PERMISSIONS), Role::query()->where('name', 'Administrator')->firstOrFail()->permissions()->count());
    }

    private function permissionsReferencedInApplicationCode(): array
    {
        $permissions = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/hasPermission\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',
                file_get_contents($file->getPathname()),
                $matches
            );
            array_push($permissions, ...$matches[1]);
        }

        return array_values(array_unique($permissions));
    }
}
