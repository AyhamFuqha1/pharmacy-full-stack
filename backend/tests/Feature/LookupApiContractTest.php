<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LookupApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_account_lookup_requires_authentication_and_an_existing_accounting_permission(): void
    {
        $this->getJson('/api/lookups/accounts')->assertUnauthorized();

        $this->actingAs($this->userWithPermissions([]), 'sanctum')
            ->getJson('/api/lookups/accounts')
            ->assertForbidden();

        $this->actingAs($this->userWithPermissions(['accounting.reports']), 'sanctum')
            ->getJson('/api/lookups/accounts')
            ->assertOk();
    }

    public function test_account_lookup_searches_and_filters_expense_and_income_accounts(): void
    {
        $this->actingAs($this->userWithPermissions(['expenses.view']), 'sanctum');

        $this->getJson('/api/lookups/accounts?q=Operating&type=expense')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', '5200')
            ->assertJsonPath('data.0.account_type', 'expense');

        $this->getJson('/api/lookups/accounts?search=Income&account_type=revenue')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($this->userWithPermissions(['other_income.view']), 'sanctum')
            ->getJson('/api/lookups/accounts?search=Income&account_type=revenue')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', '4200');
    }

    public function test_account_lookup_defaults_to_active_and_never_exposes_private_account_fields(): void
    {
        Account::query()->create([
            'code' => '5299', 'name' => 'Inactive Expense', 'account_type' => 'expense',
            'normal_balance' => 'debit', 'active' => false, 'is_system' => false,
            'description' => 'private implementation note',
        ]);
        $this->actingAs($this->userWithPermissions(['accounting.reports']), 'sanctum');

        $this->getJson('/api/lookups/accounts?type=expense&per_page=100')
            ->assertOk()
            ->assertJsonMissing(['code' => '5299'])
            ->assertJsonMissingPath('data.0.normal_balance')
            ->assertJsonMissingPath('data.0.is_system')
            ->assertJsonMissingPath('data.0.description')
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'account_type', 'active']]]);
    }

    public function test_bank_account_lookup_is_authorized_searchable_and_safe(): void
    {
        $bankId = Account::query()->where('code', '1200')->value('id');
        Account::query()->create([
            'code' => '1210', 'name' => 'Main Branch Bank', 'parent_id' => $bankId,
            'account_type' => 'asset', 'normal_balance' => 'debit', 'active' => true,
            'is_system' => false, 'description' => 'secret bank metadata',
        ]);

        $this->actingAs($this->userWithPermissions(['accounting.reports']), 'sanctum')
            ->getJson('/api/lookups/bank-accounts')
            ->assertForbidden();

        $this->actingAs($this->userWithPermissions(['reconciliation.manage']), 'sanctum')
            ->getJson('/api/lookups/bank-accounts?q=Main')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.display_name', 'Main Branch Bank')
            ->assertJsonMissingPath('data.0.description')
            ->assertJsonMissingPath('data.0.normal_balance')
            ->assertJsonStructure(['data' => [['id', 'code', 'display_name', 'active']]]);
    }

    public function test_party_lookup_returns_only_active_customers_and_suppliers_with_unambiguous_keys(): void
    {
        DB::table('customers')->insert([
            ['id' => 1001, 'name' => 'Alpha Shared', 'phone_num' => '111', 'city' => 'A', 'deleted_at' => null],
            ['id' => 1002, 'name' => 'Deleted Customer', 'phone_num' => '999', 'city' => 'A', 'deleted_at' => now()],
        ]);
        DB::table('providers')->insert([
            ['id' => 1001, 'name' => 'Alpha Supplier', 'phone_num' => '222', 'city' => 'B'],
            ['id' => 1003, 'name' => 'Beta Supplier', 'phone_num' => '333', 'city' => 'B'],
        ]);

        $this->actingAs($this->userWithPermissions(['reports.view_financial']), 'sanctum')
            ->getJson('/api/lookups/parties?q=Alpha&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => 1001, 'party_type' => 'customer', 'display_name' => 'Alpha Shared', 'phone_reference' => '111'])
            ->assertJsonFragment(['id' => 1001, 'party_type' => 'supplier', 'display_name' => 'Alpha Supplier', 'phone_reference' => '222'])
            ->assertJsonMissing(['display_name' => 'Deleted Customer'])
            ->assertJsonMissingPath('data.0.city')
            ->assertJsonMissingPath('data.0.dept');

        $this->getJson('/api/lookups/parties?search=333&party_type=supplier')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.party_type', 'supplier')
            ->assertJsonPath('data.0.display_name', 'Beta Supplier');
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::query()->create(['name' => 'Lookup role '.uniqid()]);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role' => $role->id, 'force_change_password' => false]);
    }
}
