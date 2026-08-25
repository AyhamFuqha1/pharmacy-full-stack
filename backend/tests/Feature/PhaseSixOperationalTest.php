<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\DashBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseSixOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_cashier_can_create_sales_but_cannot_access_accounting_reports(): void
    {
        $cashier = $this->userWithRole('Cashier');
        $this->actingAs($cashier, 'sanctum');

        $this->getJson('/api/accounting/reports/trial-balance')->assertForbidden();
        $this->postJson('/api/sales-invoices', [])->assertStatus(422);
    }

    public function test_accountant_can_access_reports_but_pharmacist_cannot_manage_users(): void
    {
        $this->actingAs($this->userWithRole('Accountant'), 'sanctum');
        $this->getJson('/api/accounting/reports/trial-balance')->assertOk();

        $this->actingAs($this->userWithRole('Pharmacist'), 'sanctum');
        $this->postJson('/api/register', [])->assertForbidden();
    }

    public function test_pharmacist_can_manage_products(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->userWithRole('Pharmacist'), 'sanctum');

        $this->getJson('/api/product')->assertOk();
        $this->putJson('/api/product/' . $product->id, [])->assertOk();
    }

    public function test_authenticated_product_changes_create_audit_log(): void
    {
        $user = $this->userWithRole('Administrator');
        $this->actingAs($user, 'sanctum');

        $product = Product::factory()->create(['name' => 'Audited Product']);
        $product->update(['name' => 'Renamed Product']);
        $product->delete();

        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'model_name' => Product::class, 'action_type' => 'create']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'model_name' => Product::class, 'action_type' => 'update']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'model_name' => Product::class, 'action_type' => 'delete']);
    }

    public function test_dashboard_sales_profit_and_inventory_value_are_accurate(): void
    {
        $user = $this->userWithRole('Administrator');
        $this->actingAs($user, 'sanctum');
        $today = now()->toDateString();
        $customerId = DB::table('customers')->insertGetId(['name' => 'Dashboard Customer', 'dept' => 0]);
        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'customer_id' => $customerId, 'user_id' => $user->id, 'total_price' => 100,
            'discount' => 0, 'profit' => 40, 'date' => $today,
        ]);
        $this->createPeriod($today);
        $purchaseId = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => DB::table('providers')->insertGetId(['name' => 'Dashboard Provider', 'dept' => 0]),
            'user_id' => $user->id, 'amount' => 120, 'descount' => 0, 'date' => $today,
        ]);
        app(AccountingPostingService::class)->postPurchaseInvoice($purchaseId);
        $this->postSalesAccounting($invoiceId);

        $dashboard = app(DashBoardService::class)->index();

        $this->assertSame(100.0, $dashboard['todays_sales']);
        $this->assertSame(40.0, $dashboard['todays_profit']);
        $this->assertSame(60.0, $dashboard['inventory_value']['value']);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => Role::query()->where('name', $role)->value('id')]);
    }

    private function createPeriod(string $date): void
    {
        \App\Models\AccountingPeriod::create([
            'name' => 'Current period', 'period_year' => (int) date('Y', strtotime($date)), 'period_month' => (int) date('m', strtotime($date)),
            'start_date' => date('Y-m-01', strtotime($date)), 'end_date' => date('Y-m-t', strtotime($date)),
        ]);
    }

    private function postSalesAccounting(int $invoiceId): void
    {
        DB::table('prod_sales')->insert([
            'sales_invoice_id' => $invoiceId, 'batch_id' => $this->batchId(), 'amount' => 1, 'price' => 100,
            'discount' => 0, 'cost_price' => 60, 'line_total' => 100, 'invoice_discount_share' => 0,
            'final_total' => 100, 'profit' => 40,
        ]);
        app(AccountingPostingService::class)->postSalesInvoice($invoiceId);
    }

    private function batchId(): int
    {
        $product = Product::factory()->create();
        return DB::table('product_batches')->insertGetId([
            'product_id' => $product->id, 'batch_number' => 'DASHBOARD', 'expiry_date' => '2027-01-01',
            'quantity' => 10, 'purchase_price' => 60, 'sale_price' => 100,
        ]);
    }
}
