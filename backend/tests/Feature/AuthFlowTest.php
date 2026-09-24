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

    public function test_create_admin_command_accepts_non_interactive_options(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'Acceptance Admin',
            '--email' => 'acceptance.admin@example.test',
            '--password' => 'Acceptance-Temp-2026!',
            '--password_confirmation' => 'Acceptance-Temp-2026!',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'name' => 'Acceptance Admin',
            'email' => 'acceptance.admin@example.test',
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
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
        $response->assertJsonPath('success', true)->assertJsonPath('message', 'Login successful');
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'active' => 1]);
    }

    public function test_authenticated_user_can_be_loaded_then_logged_out(): void
    {
        $user = User::factory()->create([
            'role' => 1,
            'password' => Hash::make('StrongPass123!'),
            'force_change_password' => false,
        ]);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'StrongPass123!',
        ])->assertOk();

        $token = $login->json('data.token');
        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'active' => 0]);
    }

    public function test_login_with_active_attendance_recovers_by_replacing_the_token(): void
    {
        $user = User::factory()->create([
            'role' => 1,
            'password' => Hash::make('StrongPass123!'),
            'force_change_password' => false,
        ]);
        $oldToken = $user->createToken('api-token')->plainTextToken;
        \DB::table('attendance_logs')->insert([
            'user_id' => $user->id,
            'work_date' => now()->toDateString(),
            'check_in_at' => now(),
            'active' => true,
        ]);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'StrongPass123!',
        ])->assertOk()->assertJsonPath('message', 'Login successful');

        $this->assertSame(1, \DB::table('attendance_logs')->where('user_id', $user->id)->count());
        $this->withToken($oldToken)->getJson('/api/user')->assertUnauthorized();
        $this->withToken($login->json('data.token'))->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
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

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertDatabaseHas('users', ['email' => 'employee2@example.com', 'role' => $normalRoleId]);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'users', 'action' => 'create']);
    }
}
