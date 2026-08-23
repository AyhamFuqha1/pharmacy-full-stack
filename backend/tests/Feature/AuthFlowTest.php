<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_are_seeded(): void
    {
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder'])->assertSuccessful();

        $this->assertDatabaseHas('roles', ['id' => 1, 'name' => 'Administrator']);
        $this->assertDatabaseHas('roles', ['id' => 2, 'name' => 'Normal User']);
    }

    public function test_create_admin_command_creates_first_admin(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'System Admin',
            '--email' => 'admin@example.com',
            '--password' => 'StrongPass123!',
            '--password_confirmation' => 'StrongPass123!',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame(1, (int) $user->role);
        $this->assertTrue(Hash::check('StrongPass123!', $user->password));
    }

    public function test_login_returns_token_for_valid_user(): void
    {
        $roleId = 1;
        $user = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin2@example.com',
            'role' => $roleId,
            'password' => Hash::make('StrongPass123!'),
            'force_change_password' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin2@example.com',
            'password' => 'StrongPass123!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Login successful');
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'active' => 1]);
    }

    public function test_normal_user_cannot_register_another_user(): void
    {
        $adminRoleId = 1;
        $normalRoleId = 2;

        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin3@example.com',
            'role' => $adminRoleId,
            'password' => Hash::make('StrongPass123!'),
        ]);

        $normalUser = User::factory()->create([
            'name' => 'Employee',
            'email' => 'employee@example.com',
            'role' => $normalRoleId,
            'password' => Hash::make('StrongPass123!'),
        ]);

        $this->actingAs($normalUser, 'sanctum');

        $response = $this->postJson('/api/register', [
            'name' => 'Another Employee',
            'email' => 'second@example.com',
            'role' => $normalRoleId,
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_register_employee(): void
    {
        $adminRoleId = 1;
        $normalRoleId = 2;

        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin4@example.com',
            'role' => $adminRoleId,
            'password' => Hash::make('StrongPass123!'),
        ]);

        $this->actingAs($admin, 'sanctum');

        $response = $this->postJson('/api/register', [
            'name' => 'Employee',
            'email' => 'employee2@example.com',
            'role' => $normalRoleId,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'employee2@example.com', 'role' => $normalRoleId]);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'users', 'action' => 'create']);
    }
}
