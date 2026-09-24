<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesInventoryOperatorAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $role = Role::query()->where('name', 'Sales & Inventory Operator')->firstOrFail();
        $this->operator = User::factory()->create(['role'=>$role->id, 'branch_id'=>DB::table('branches')->where('code','MAIN')->value('id'), 'force_change_password'=>false]);
        $this->actingAs($this->operator, 'sanctum');
    }

    public function test_role_has_exact_operational_permissions_without_sensitive_grants(): void
    {
        $expected = AuthorizationSeeder::ROLE_PERMISSIONS['Sales & Inventory Operator'];
        $actual = $this->operator->roleRelation->permissions()->pluck('name')->all();
        $this->assertEqualsCanonicalizing($expected, $actual);
        foreach (['purchases.manage','suppliers.view','reports.view_profit','reports.view_cost','reports.view_financial','accounting.reports','accounting.vouchers','users.manage','inventory.adjust','inventory.count','sales.discount','purchase_returns.override_credit','controlled.view'] as $denied) {
            $this->assertNotContains($denied, $actual);
        }
    }

    public function test_sales_products_batches_reports_and_transfers_remain_authorized_without_cost_fields(): void
    {
        $product = Product::factory()->create();
        $batch = DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'branch_id'=>$this->operator->branch_id,'batch_number'=>'OP-1','expiry_date'=>now()->addYear(),'purchase_price'=>4,'sale_price'=>7,'quantity'=>2,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);

        $this->getJson('/api/sales-invoices')->assertOk();
        $this->getJson('/api/product')->assertOk();
        $this->getJson("/api/product/{$product->id}")->assertOk();
        $this->getJson('/api/batch')->assertOk()->assertJsonMissingPath('data.0.purchase_price');
        $this->getJson("/api/batch/{$batch}")->assertOk()->assertJsonMissingPath('data.purchase_price');
        $this->getJson('/api/customers')->assertOk();
        $this->getJson("/api/products/{$product->id}/360")->assertOk()
            ->assertJsonMissingPath('data.gross_profit')->assertJsonMissingPath('data.cogs')->assertJsonMissingPath('data.revenue')->assertJsonMissingPath('data.purchase_value');
        $this->getJson('/api/reports/catalog')->assertOk();
        $this->postJson('/api/reports/query', ['report_key'=>'stock_movements'])->assertOk();
        $this->getJson("/api/products/{$product->id}/stock-ledger")->assertOk()->assertJsonMissingPath('data.0.cost_basis');
        $this->getJson("/api/batches/{$batch}/360")->assertOk()
            ->assertJsonMissingPath('data.unit_cost')->assertJsonMissingPath('data.originating_purchases')
            ->assertJsonMissingPath('data.purchase_returns');
        $this->getJson('/api/print-documents/sale/999999')->assertNotFound();
    }

    public function test_purchase_supplier_and_financial_endpoints_are_denied_by_direct_request(): void
    {
        foreach ([
            ['get','/api/purchase-invoices'], ['get','/api/purchase-invoices/1'],
            ['post','/api/purchase-invoices'], ['put','/api/purchase-invoices/1'],
            ['post','/api/purchase-invoices/1/post'], ['delete','/api/purchase-invoices/1'],
            ['get','/api/purchase-returns'], ['get','/api/purchase-returns/1'], ['post','/api/purchase-invoices/1/returns'],
            ['get','/api/payment-vouchers'], ['post','/api/payment-vouchers'],
            ['get','/api/providers'], ['get','/api/providers/1'], ['get','/api/providers/1/360'],
            ['get','/api/accounting/reports/ap-aging'],
        ] as [$method,$uri]) {
            $this->json(strtoupper($method), $uri)->assertForbidden();
        }
    }

    public function test_operator_can_create_and_view_sales_without_cost_or_profit_fields(): void
    {
        $product = Product::factory()->create();
        $batch = DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'branch_id'=>$this->operator->branch_id,'batch_number'=>'SALE-OP','expiry_date'=>now()->addYear(),'purchase_price'=>4,'sale_price'=>7,'quantity'=>3,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
        $customer = DB::table('customers')->insertGetId(['name'=>'Operator Customer','phone_num'=>uniqid(),'city'=>'Test']);
        $sale = $this->postJson('/api/sales-invoices', ['customer_id'=>$customer,'date'=>now()->toDateString(),'discount'=>0,'paid_amount'=>7,'payment_method'=>'cash','items'=>[['batch_id'=>$batch,'amount'=>1,'price'=>7,'discount'=>0]]])
            ->assertCreated()->assertJsonMissingPath('data.profit')->json('data.id');
        $this->getJson("/api/sales-invoices/{$sale}")->assertOk()
            ->assertJsonMissingPath('data.profit')->assertJsonMissingPath('data.items.0.cost_price')->assertJsonMissingPath('data.items.0.profit');
    }

    public function test_administrator_keeps_purchase_access_and_complete_cost_data(): void
    {
        $product = Product::factory()->create();
        $batch = DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'branch_id'=>$this->operator->branch_id,'batch_number'=>'ADMIN-COST','expiry_date'=>now()->addYear(),'purchase_price'=>4,'sale_price'=>7,'quantity'=>2,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
        $admin = User::factory()->create(['role'=>1,'force_change_password'=>false]);
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/purchase-invoices')->assertOk();
        $this->getJson("/api/batch/{$batch}")->assertOk()->assertJsonPath('data.purchase_price', '4.00');
        $this->getJson("/api/batches/{$batch}/360")->assertOk()->assertJsonPath('data.unit_cost', 4);
    }

    public function test_dashboard_is_operational_only(): void
    {
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonMissingPath('todays_sales')->assertJsonMissingPath('todays_net_sales')
            ->assertJsonMissingPath('todays_profit')->assertJsonMissingPath('todays_net_profit')
            ->assertJsonMissingPath('inventory_value')->assertJsonMissingPath('customer_balances')->assertJsonMissingPath('supplier_balances')
            ->assertJsonStructure(['today','low_stock_products','expiring_batches','top_selling_products']);
    }

    public function test_direct_sensitive_api_access_is_denied(): void
    {
        foreach ([
            ['get','/api/accounting/reports/general-ledger'], ['get','/api/accounting/reports/trial-balance'],
            ['get','/api/expenses'], ['get','/api/other-incomes'], ['get','/api/bank-reconciliation/movements'],
            ['get','/api/cheques'], ['get','/api/accounting-periods'], ['get','/api/users'], ['get','/api/roles'],
            ['post','/api/stock-adjustments'], ['post','/api/opening-balances'], ['post','/api/stock-counts'],
            ['get','/api/cashier-shifts'], ['get','/api/controlled-medicine-ledger'],
        ] as [$method,$uri]) {
            $this->json(strtoupper($method), $uri)->assertForbidden();
        }
        $this->postJson('/api/print-documents/customer_statement', ['customer_id'=>1])->assertForbidden();
    }

    public function test_role_selector_is_localized_without_renaming_persisted_roles(): void
    {
        $admin = User::factory()->create(['role'=>1,'force_change_password'=>false]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/roles')->assertOk()->assertJsonFragment(['name'=>'Sales & Inventory Operator']);
        $source = file_get_contents(base_path('../frontend/src/app/components/AdminSettingsSections.tsx'));
        $translations = file_get_contents(base_path('../frontend/src/i18n/resources.ts'));
        $this->assertStringContainsString('{t(role.name)}', $source);
        foreach (['Administrator','Normal User','Accountant','Cashier','Pharmacist','Sales & Inventory Operator'] as $role) $this->assertStringContainsString($role, $translations);
    }

    public function test_frontend_navigation_and_routes_use_permission_guards(): void
    {
        $sidebar = file_get_contents(base_path('../frontend/src/app/components/Sidebar.tsx'));
        $routes = file_get_contents(base_path('../frontend/src/app/routes.ts'));
        $guards = file_get_contents(base_path('../frontend/src/auth/RouteGuards.tsx'));
        $this->assertStringContainsString('permissions: ["purchases.manage", "accounting.vouchers"]', $sidebar);
        $this->assertStringContainsString('permission: "accounting.vouchers"', $sidebar);
        $this->assertStringContainsString('PermissionRoute', $routes);
        $this->assertStringContainsString('path: "purchases/:id"', $routes);
        $this->assertStringContainsString('path: "payment-vouchers"', $routes);
        $this->assertStringContainsString('permissions.some((permission) => hasPermission(permission))', $guards);
    }
}
