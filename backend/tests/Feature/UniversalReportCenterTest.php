<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UniversalReportCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->branch = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->admin = User::factory()->create([
            'role' => 1,
            'branch_id' => $this->branch,
            'force_change_password' => false,
        ]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_catalog_exposes_only_the_six_report_center_types(): void
    {
        $response = $this->getJson('/api/reports/catalog')->assertOk();
        $this->assertEqualsCanonicalizing([
            'stock_movements',
            'product_statement',
            'batch_statement',
            'ar_aging',
            'ap_aging',
            'controlled_medicine_ledger',
        ], collect($response->json('data'))->pluck('key')->all());
    }

    public function test_each_visible_key_resolves_to_its_own_report(): void
    {
        [$product, $batch] = $this->movement('REPORT-A', 'A');

        $cases = [
            ['stock_movements', []],
            ['product_statement', ['product_id' => $product->id]],
            ['batch_statement', ['batch_id' => $batch]],
            ['ar_aging', []],
            ['ap_aging', []],
            ['controlled_medicine_ledger', []],
        ];

        foreach ($cases as [$key, $filters]) {
            $this->postJson('/api/reports/query', [
                'report_key' => $key,
                'filters' => $filters,
            ])->assertOk()->assertJsonPath('data.report.key', $key);
        }
    }

    public function test_product_and_batch_reports_are_specialized_and_batch_is_filtered(): void
    {
        [$productA, $batchA] = $this->movement('REPORT-A', 'A');
        [, $batchB] = $this->movement('REPORT-B', 'B');

        $this->postJson('/api/reports/query', [
            'report_key' => 'product_statement',
            'filters' => ['product_id' => $productA->id],
        ])->assertOk()
            ->assertJsonPath('data.report.key', 'product_statement')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.rows.0.product_name', $productA->name);

        $batch = $this->postJson('/api/reports/query', [
            'report_key' => 'batch_statement',
            'filters' => ['batch_id' => $batchA],
            'columns' => ['batch_id', 'batch_number', 'product_name', 'quantity'],
        ])->assertOk()->assertJsonPath('data.report.key', 'batch_statement');

        $this->assertSame([$batchA], collect($batch->json('data.rows'))->pluck('batch_id')->unique()->values()->all());
        $this->assertNotContains($batchB, collect($batch->json('data.rows'))->pluck('batch_id'));
    }

    public function test_unknown_keys_do_not_fall_back_and_historical_saved_reports_still_execute(): void
    {
        $this->postJson('/api/reports/query', ['report_key' => 'unknown_report'])
            ->assertUnprocessable();

        Product::factory()->create(['name' => 'Historical saved product']);
        $saved = DB::table('saved_reports')->insertGetId([
            'owner_id' => $this->admin->id,
            'name' => 'Historical products',
            'report_key' => 'products',
            'filters' => json_encode(['search' => 'Historical saved']),
            'visible_columns' => json_encode(['id', 'name']),
            'sort' => 'name',
            'direction' => 'asc',
            'is_shared' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/reports/saved/$saved/execute")
            ->assertOk()
            ->assertJsonPath('data.report.key', 'products')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_frontend_preserves_query_keys_and_uses_readable_entity_filters(): void
    {
        $page = file_get_contents(base_path('../frontend/src/app/pages/UniversalReportsPage.tsx'));
        $this->assertStringContainsString('setParams({ report: item.key })', $page);
        $this->assertStringContainsString('UnsupportedReport', $page);
        $this->assertStringContainsString('productOptions[String(value)]?.label', $page);
        $this->assertStringNotContainsString('find((item) => item.key === reportKey) ||', $page);
    }

    private function movement(string $name, string $batchNumber): array
    {
        $product = Product::factory()->create([
            'name' => $name,
            'barcode' => "BAR-$batchNumber",
            'is_controlled' => $name === 'REPORT-A',
        ]);
        $batch = DB::table('product_batches')->insertGetId([
            'product_id' => $product->id,
            'branch_id' => $this->branch,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->addYear()->toDateString(),
            'purchase_price' => 2,
            'sale_price' => 3,
            'quantity' => 1,
            'inventory_state' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock')->insert([
            'batch_id' => $batch,
            'branch_id' => $this->branch,
            'quantity' => 1,
            'type' => 'ADJUSTMENT_IN',
            'reference_type' => 'stock_adjustment',
            'reference_id' => null,
            'user_id' => $this->admin->id,
            'created_at' => now(),
        ]);

        return [$product, $batch];
    }
}
