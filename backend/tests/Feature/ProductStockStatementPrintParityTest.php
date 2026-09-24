<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductStockStatementPrintParityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $productId;
    private int $mainBranch;
    private int $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->mainBranch = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->otherBranch = DB::table('branches')->insertGetId(['name'=>'Other','code'=>'OTHER-STMT','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $this->admin = User::factory()->create(['role'=>1,'branch_id'=>$this->mainBranch,'force_change_password'=>false]);
        $this->productId = Product::factory()->create()->id;
        $mainBatch = $this->batch($this->mainBranch, 'MAIN-STMT');
        $otherBatch = $this->batch($this->otherBranch, 'OTHER-STMT');

        $this->movement($mainBatch, $this->mainBranch, 10, 'OPENING_STOCK_IN', now()->subDays(10));
        $this->movement($mainBatch, $this->mainBranch, 3, 'SALE_OUT', now()->subDays(5));
        $this->movement($mainBatch, $this->mainBranch, 2, 'PURCHASE_IN', now()->subDays(2));
        $this->movement($otherBatch, $this->otherBranch, 20, 'OPENING_STOCK_IN', now()->subDays(10));
        $this->movement($otherBatch, $this->otherBranch, 4, 'SALE_OUT', now()->subDays(5));
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_screen_and_print_have_branch_movement_and_date_filter_parity(): void
    {
        $filters = ['branch_id'=>$this->mainBranch,'from'=>now()->subDays(6)->toDateString(),'to'=>now()->toDateString()];
        $screen = $this->getJson('/api/products/'.$this->productId.'/stock-statement?'.http_build_query($filters))->assertOk()->json('data');
        $print = $this->postJson('/api/print-documents/product_statement', array_merge(['product_id'=>$this->productId], $filters))->assertOk()->json('data.content');

        $this->assertSame(['PURCHASE_IN','SALE_OUT'], array_column($screen['movements']['data'], 'movement_type'));
        $this->assertSame(array_column($screen['movements']['data'], 'id'), array_column($print['rows_lines'], 'id'));
        $this->assertSame($screen['opening_quantity'], $print['opening_balance_stock']);
        $this->assertSame($screen['closing_quantity'], $print['closing_balance_stock']);
        $this->assertSame($screen['opening_quantity'], $print['totals_summary']['opening_quantity']);
        $this->assertSame($screen['closing_quantity'], $print['totals_summary']['closing_quantity']);
        $this->assertSame(10, (int) $screen['opening_quantity']);
        $this->assertSame(9, (int) $screen['closing_quantity']);

        $movementFilters = array_merge($filters, ['movement_type'=>'SALE_OUT']);
        $movementScreen = $this->getJson('/api/products/'.$this->productId.'/stock-statement?'.http_build_query($movementFilters))->assertOk()->json('data');
        $movementPrint = $this->postJson('/api/print-documents/product_statement', array_merge(['product_id'=>$this->productId], $movementFilters))->assertOk()->json('data.content');
        $this->assertSame(['SALE_OUT'], array_column($movementScreen['movements']['data'], 'movement_type'));
        $this->assertSame(array_column($movementScreen['movements']['data'], 'id'), array_column($movementPrint['rows_lines'], 'id'));
        $this->assertSame($movementScreen['opening_quantity'], $movementPrint['opening_balance_stock']);
        $this->assertSame($movementScreen['closing_quantity'], $movementPrint['closing_balance_stock']);
    }

    public function test_cost_fields_do_not_leak_to_screen_or_print_without_cost_permission(): void
    {
        $viewer = $this->userWithPermissions(['reports.view_operational','reports.print']);
        $this->actingAs($viewer, 'sanctum');
        $filters = ['branch_id'=>$this->mainBranch,'from'=>now()->subDays(6)->toDateString(),'to'=>now()->toDateString()];

        $this->getJson('/api/products/'.$this->productId.'/stock-statement?'.http_build_query($filters))
            ->assertOk()->assertJsonMissingPath('data.movements.data.0.cost_basis');
        $this->postJson('/api/print-documents/product_statement', array_merge(['product_id'=>$this->productId], $filters))
            ->assertOk()->assertJsonMissingPath('data.content.rows_lines.0.cost_basis');
    }

    public function test_movements_are_returned_without_an_opening_baseline_and_are_newest_first(): void
    {
        $product = Product::factory()->create();
        $batch = DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'branch_id'=>$this->mainBranch,'batch_number'=>'NO-BASELINE','expiry_date'=>now()->addYear(),'purchase_price'=>6,'sale_price'=>10,'quantity'=>3,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
        $older = DB::table('stock')->insertGetId(['batch_id'=>$batch,'branch_id'=>null,'quantity'=>2,'type'=>'PURCHASE_IN','reference_type'=>'test','reference_id'=>10,'user_id'=>$this->admin->id,'unit_cost'=>6,'created_at'=>now()->subDay()]);
        $newer = DB::table('stock')->insertGetId(['batch_id'=>$batch,'branch_id'=>$this->mainBranch,'quantity'=>1,'type'=>'SALE_OUT','reference_type'=>'test','reference_id'=>11,'user_id'=>$this->admin->id,'unit_cost'=>6,'created_at'=>now()]);

        $response = $this->getJson("/api/products/{$product->id}/stock-statement")
            ->assertOk()->assertJsonPath('data.running_balance_available', false)->assertJsonPath('data.movements.total', 2);
        $this->assertSame([$newer, $older], array_column($response->json('data.movements.data'), 'id'));
    }

    public function test_all_and_specific_branch_batch_date_and_type_filters_use_legacy_main_semantics(): void
    {
        $mainBatch = (int) DB::table('product_batches')->where('product_id',$this->productId)->where('branch_id',$this->mainBranch)->value('id');
        $legacy = DB::table('stock')->insertGetId(['batch_id'=>$mainBatch,'branch_id'=>null,'quantity'=>1,'type'=>'ADJUSTMENT_IN','reference_type'=>'test','reference_id'=>12,'user_id'=>$this->admin->id,'unit_cost'=>6,'created_at'=>now()->subDay()]);

        $all = $this->getJson("/api/products/{$this->productId}/stock-statement")->assertOk()->json('data.movements');
        $this->assertContains($legacy, array_column($all['data'], 'id'));
        $this->assertSame(6, $all['total']);

        $main = $this->getJson("/api/products/{$this->productId}/stock-statement?branch_id={$this->mainBranch}")->assertOk()->json('data.movements.data');
        $this->assertContains($legacy, array_column($main, 'id'));
        $this->assertNotContains($this->otherBranch, array_column($main, 'branch_id'));

        $other = $this->getJson("/api/products/{$this->productId}/stock-statement?branch_id={$this->otherBranch}")->assertOk()->json('data.movements.data');
        $this->assertCount(2, $other);

        $batchRows = $this->getJson("/api/products/{$this->productId}/stock-statement?batch_id={$mainBatch}")->assertOk()->json('data.movements.data');
        $this->assertSame([$mainBatch], array_values(array_unique(array_column($batchRows, 'batch_id'))));

        $filtered = $this->getJson('/api/products/'.$this->productId.'/stock-statement?'.http_build_query(['from'=>now()->subDays(2)->toDateString(),'to'=>now()->toDateString(),'movement_type'=>'ADJUSTMENT_IN']))->assertOk()->json('data.movements.data');
        $this->assertSame([$legacy], array_column($filtered, 'id'));
    }

    public function test_product_statement_reconciles_with_universal_stock_movements(): void
    {
        $statementIds = collect($this->getJson("/api/products/{$this->productId}/stock-statement?per_page=100")->assertOk()->json('data.movements.data'))->pluck('id')->all();
        $universalIds = collect($this->postJson('/api/reports/query', ['report_key'=>'stock_movements','filters'=>['product_id'=>$this->productId],'columns'=>['movement_id'],'per_page'=>100])->assertOk()->json('data.rows'))->pluck('movement_id')->all();
        $this->assertSame($universalIds, $statementIds);
    }

    public function test_product_360_uses_one_localized_empty_state_message(): void
    {
        $page = file_get_contents(base_path('../frontend/src/app/pages/Product360WorkspacePage.tsx'));
        $translations = file_get_contents(base_path('../frontend/src/i18n/resources.ts'));

        $this->assertSame(1, substr_count($page, 'No stock movements match these filters.'));
        $this->assertStringNotContainsString('Clear the filters to view all movements.', $page);
        $this->assertStringContainsString('\\u0644\\u0627 \\u062a\\u0648\\u062c\\u062f \\u062d\\u0631\\u0643\\u0627\\u062a', $translations);
    }

    public function test_product_360_movement_sources_use_only_mounted_detail_routes(): void
    {
        $page = file_get_contents(base_path('../frontend/src/app/pages/Product360WorkspacePage.tsx'));

        foreach ([
            'purchase: `/purchases/${id}`',
            'purchase_invoice: `/purchases/${id}`',
            'sale: `/sales/${id}`',
            'sales_return: `/returns/sales/${id}`',
            'purchase_return: `/returns/purchases/${id}`',
            'prescription: `/prescriptions/${id}`',
        ] as $mapping) {
            $this->assertStringContainsString($mapping, $page);
        }
        foreach (['/inventory/stock-counts/${id}', '/inventory/adjustments/${id}', '/inventory/transfers/${id}', '/inventory/opening-balances/${id}'] as $unsupported) {
            $this->assertStringNotContainsString($unsupported, $page);
        }
        $this->assertStringContainsString('hidden lg:table-cell', $page);
        $this->assertStringContainsString('hidden xl:table-cell', $page);
    }

    private function batch(int $branchId, string $number): int
    {
        return DB::table('product_batches')->insertGetId(['product_id'=>$this->productId,'branch_id'=>$branchId,'batch_number'=>$number,'expiry_date'=>now()->addYear(),'purchase_price'=>6,'sale_price'=>10,'quantity'=>20,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
    }

    private function movement(int $batchId, int $branchId, int $quantity, string $type, $createdAt): void
    {
        DB::table('stock')->insert(['batch_id'=>$batchId,'branch_id'=>$branchId,'quantity'=>$quantity,'type'=>$type,'reference_type'=>'test','reference_id'=>1,'user_id'=>$this->admin->id,'unit_cost'=>6,'created_at'=>$createdAt]);
    }

    private function userWithPermissions(array $names): User
    {
        $role = DB::table('roles')->insertGetId(['name'=>'Statement Viewer '.uniqid(),'created_at'=>now(),'updated_at'=>now()]);
        foreach (DB::table('permissions')->whereIn('name',$names)->pluck('id') as $permission) DB::table('role_permissions')->insert(['role_id'=>$role,'permission_id'=>$permission]);
        return User::factory()->create(['role'=>$role,'force_change_password'=>false]);
    }
}
