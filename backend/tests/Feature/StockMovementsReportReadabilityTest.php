<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockMovementsReportReadabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->branch = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->admin = User::factory()->create(['name' => 'ayham', 'role' => 1, 'branch_id' => $this->branch, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_query_uses_business_readable_default_columns_and_preserves_filters(): void
    {
        [$product, $batch, $movement] = $this->movement();

        $response = $this->postJson('/api/reports/query', [
            'report_key' => 'stock_movements',
            'filters' => ['product_id' => $product->id, 'batch_id' => $batch, 'branch_id' => $this->branch, 'type' => 'PURCHASE_IN', 'reference_type' => 'purchase'],
        ])->assertOk();

        $this->assertSame(['created_at', 'product_name', 'product_barcode', 'batch_number', 'batch_expiry', 'branch', 'type', 'quantity', 'unit_cost', 'reference_type', 'reference', 'user_name', 'movement_id'], $response->json('data.columns'));
        $response->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.rows.0.product_name', 'منتج الاختبار A')
            ->assertJsonPath('data.rows.0.product_barcode', '625202600001')
            ->assertJsonPath('data.rows.0.batch_number', '5')
            ->assertJsonPath('data.rows.0.batch_expiry', '2029-02-22')
            ->assertJsonPath('data.rows.0.branch', 'MAIN — Main Branch')
            ->assertJsonPath('data.rows.0.user_name', 'ayham')
            ->assertJsonPath('data.rows.0.reference', 'PUR-2026-000008')
            ->assertJsonPath('data.rows.0.reference_id', fn ($id) => is_int($id) && $id > 0)
            ->assertJsonPath('data.rows.0.movement_id', $movement);
        $this->assertNotContains('batch_id', $response->json('data.columns'));
        $this->assertNotContains('branch_id', $response->json('data.columns'));
        $this->assertNotContains('user_id', $response->json('data.columns'));
        $this->assertNotContains('reference_id', $response->json('data.columns'));
    }

    public function test_print_and_csv_use_the_same_business_projection(): void
    {
        $this->movement();
        $payload = ['report_key' => 'stock_movements', 'filters' => ['branch_id' => $this->branch]];

        $print = $this->postJson('/api/reports/print-data', $payload)->assertOk();
        $print->assertJsonPath('data.rows.0.product_name', 'منتج الاختبار A')
            ->assertJsonPath('data.rows.0.batch_number', '5')
            ->assertJsonPath('data.rows.0.branch', 'MAIN — Main Branch')
            ->assertJsonPath('data.rows.0.reference', 'PUR-2026-000008')
            ->assertJsonPath('data.rows.0.user_name', 'ayham');

        $csv = $this->post('/api/reports/export/csv', $payload);
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('product_name', $content);
        $this->assertStringContainsString('منتج الاختبار A', $content);
        $this->assertStringContainsString('MAIN — Main Branch', $content);
        $this->assertStringContainsString('PUR-2026-000008', $content);
        $this->assertStringNotContainsString('batch_id,branch_id', $content);
    }

    public function test_missing_optional_historical_relations_use_safe_fallbacks(): void
    {
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, null, 'HISTORICAL');
        DB::table('stock')->insert(['batch_id' => $batch, 'branch_id' => null, 'quantity' => 1, 'type' => 'ADJUSTMENT_IN', 'reference_type' => 'legacy_unknown', 'reference_id' => 991, 'user_id' => null, 'created_at' => now()]);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/reports/query', ['report_key' => 'stock_movements'])
            ->assertOk()
            ->assertJsonPath('data.rows.0.branch', 'فرع غير متاح')
            ->assertJsonPath('data.rows.0.user_name', 'مستخدم غير متاح')
            ->assertJsonPath('data.rows.0.reference', '991');
    }

    public function test_frontend_localizes_stable_movement_and_reference_values_for_screen_and_print(): void
    {
        $screen = file_get_contents(base_path('../frontend/src/app/pages/UniversalReportsPage.tsx'));
        $print = file_get_contents(base_path('../frontend/src/app/components/PrintableReport.tsx'));
        $resources = file_get_contents(base_path('../frontend/src/i18n/resources.ts'));

        foreach (['purchase', 'sale', 'sales_return', 'purchase_return', 'stock_adjustment', 'stock_count', 'stock_transfer'] as $value) {
            $this->assertStringContainsString($value, $screen);
            $this->assertStringContainsString($value, $print);
        }
        $this->assertStringContainsString('"Stock Adjustment Out": "تسوية مخزون صادرة"', $resources);
        $this->assertStringContainsString('"Reference Type": "نوع المرجع"', $resources);
    }

    public function test_simplified_screen_uses_only_real_source_detail_routes(): void
    {
        $screen = file_get_contents(base_path('../frontend/src/app/pages/UniversalReportsPage.tsx'));

        foreach (['purchase: `/purchases/${id}`', 'sale: `/sales/${id}`', 'sales_return: `/returns/sales/${id}`', 'purchase_return: `/returns/purchases/${id}`', 'prescription: `/prescriptions/${id}`'] as $mapping) {
            $this->assertStringContainsString($mapping, $screen);
        }
        foreach (['/inventory/stock-counts/${id}', '/inventory/adjustments/${id}', '/inventory/transfers/${id}', '/inventory/opening-balances/${id}'] as $unsupported) {
            $this->assertStringNotContainsString($unsupported, $screen);
        }
        $this->assertStringContainsString('result.report.key === "stock_movements"', $screen);
        $this->assertStringContainsString('<StockMovementResults rows={result.rows}', $screen);
    }

    private function movement(): array
    {
        $product = Product::factory()->create(['name' => 'منتج الاختبار A', 'barcode' => '625202600001']);
        $batch = $this->batch($product->id, $this->branch, '5');
        $purchase = DB::table('purchase_invoices')->insertGetId([
            'document_number' => 'PUR-2026-000008', 'provider_id' => $this->provider(), 'user_id' => $this->admin->id,
            'branch_id' => $this->branch, 'date' => '2026-09-08', 'amount' => 2, 'descount' => 0, 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $movement = DB::table('stock')->insertGetId(['batch_id' => $batch, 'branch_id' => $this->branch, 'quantity' => 1, 'type' => 'PURCHASE_IN', 'reference_type' => 'purchase', 'reference_id' => $purchase, 'user_id' => $this->admin->id, 'unit_cost' => 2, 'created_at' => now()]);

        return [$product, $batch, $movement];
    }

    private function batch(int $product, ?int $branch, string $number): int
    {
        return DB::table('product_batches')->insertGetId(['product_id' => $product, 'branch_id' => $branch, 'batch_number' => $number, 'expiry_date' => '2029-02-22', 'purchase_price' => 2, 'sale_price' => 3, 'quantity' => 1, 'inventory_state' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function provider(): int
    {
        return DB::table('providers')->insertGetId(['name' => 'Readable Supplier', 'phone_num' => fake()->unique()->numerify('######'), 'city' => 'Test']);
    }
}
