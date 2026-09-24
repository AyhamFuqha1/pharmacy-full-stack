<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsAdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_authorized_user_can_list_and_view_accounting_periods(): void
    {
        $this->actingAs($this->userForRole('Accountant'), 'sanctum');
        $period = AccountingPeriod::firstOrFail();

        $this->getJson('/api/accounting-periods?per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $period->id)
            ->assertJsonPath('meta.per_page', 5);

        $this->getJson('/api/accounting-periods/'.$period->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_accounting_period_can_be_created_and_overlap_is_rejected(): void
    {
        $this->actingAs($this->userForRole('Administrator'), 'sanctum');

        $this->postJson('/api/accounting-periods', [
            'name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'open');

        $this->postJson('/api/accounting-periods', [
            'name' => 'Overlapping 2027', 'start_date' => '2027-06-01', 'end_date' => '2027-06-30',
        ])->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'The accounting period overlaps an existing period.',
                'errors' => [],
            ]);

        $this->assertSame(1, AccountingPeriod::query()->whereYear('start_date', 2027)->count());
    }

    public function test_period_dates_are_validated_and_close_is_one_way(): void
    {
        $this->actingAs($this->userForRole('Administrator'), 'sanctum');

        $this->postJson('/api/accounting-periods', [
            'name' => 'Invalid', 'start_date' => '2028-12-31', 'end_date' => '2028-01-01',
        ])->assertUnprocessable()->assertJsonPath('success', false);

        $period = AccountingPeriod::firstOrFail();
        $this->postJson('/api/accounting-periods/'.$period->id.'/close')
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->postJson('/api/accounting-periods/'.$period->id.'/close')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only an open accounting period can be closed.');

        $this->assertDatabaseHas('accounting_periods', ['id' => $period->id, 'status' => 'closed']);
    }

    public function test_closed_period_blocks_posting(): void
    {
        $this->actingAs($this->userForRole('Administrator'), 'sanctum');
        $period = AccountingPeriod::firstOrFail();
        $this->postJson('/api/accounting-periods/'.$period->id.'/close')->assertOk();

        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => DB::table('providers')->insertGetId(['name' => 'P', 'phone_num' => '991', 'city' => 'C', 'dept' => 0]),
            'user_id' => auth()->id(), 'amount' => 10, 'descount' => 0, 'date' => now()->toDateString(),
        ]);

        $this->postJson('/api/purchase-invoices/'.$invoiceId.'/post')
            ->assertStatus(409)
            ->assertJsonPath('message', 'No open accounting period exists for the invoice date.');
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_unauthorized_user_cannot_manage_periods(): void
    {
        $this->actingAs($this->userForRole('Cashier'), 'sanctum');

        $this->getJson('/api/accounting-periods')->assertForbidden()->assertJsonPath('success', false);
        $this->postJson('/api/accounting-periods', [
            'name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ])->assertForbidden();
    }

    public function test_user_list_is_paginated_searchable_and_safe(): void
    {
        $admin = $this->userForRole('Administrator', 'Settings Admin', 'settings-admin@example.com');
        $this->userForRole('Cashier', 'Search Target', 'target@example.com');
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/users?search=target&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.email', 'target@example.com')
            ->assertJsonPath('data.0.role.name', 'Cashier');

        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data.0'));
        $this->assertArrayNotHasKey('tokens', $response->json('data.0'));
    }

    public function test_user_detail_roles_registration_and_update_return_safe_contracts(): void
    {
        $admin = $this->userForRole('Administrator');
        $cashier = Role::query()->where('name', 'Cashier')->firstOrFail();
        $accountant = Role::query()->where('name', 'Accountant')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/roles')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]])
            ->assertJsonMissingPath('data.0.permissions');

        $created = $this->postJson('/api/register', [
            'name' => 'Managed User', 'email' => 'managed@example.com', 'role' => $cashier->id,
        ])->assertCreated()
            ->assertJsonPath('message', 'User created.')
            ->assertJsonPath('data.role.id', $cashier->id)
            ->assertJsonPath('data.requires_password_change', true);

        $id = $created->json('data.id');
        $this->assertNull($created->json('data.password'));

        $this->getJson('/api/users/'.$id)->assertOk()
            ->assertJsonPath('data.email', 'managed@example.com')
            ->assertJsonMissingPath('data.password');

        $this->putJson('/api/users/'.$id, [
            'name' => 'Updated User', 'email' => 'updated@example.com', 'role' => $accountant->id,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated User')
            ->assertJsonPath('data.role.name', 'Accountant');
    }

    public function test_unauthorized_user_management_is_denied(): void
    {
        $cashier = $this->userForRole('Cashier');
        $this->actingAs($cashier, 'sanctum');

        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/users/'.$cashier->id)->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
    }

    private function userForRole(string $role, ?string $name = null, ?string $email = null): User
    {
        return User::factory()->create([
            'name' => $name ?? $role.' User',
            'email' => $email ?? strtolower(str_replace(' ', '-', $role)).uniqid().'@example.com',
            'role' => Role::query()->where('name', $role)->value('id'),
            'force_change_password' => false,
        ]);
    }
}
