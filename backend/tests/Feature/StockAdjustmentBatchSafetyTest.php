<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\ReportExplorerService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockAdjustmentBatchSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $branchA;
    private int $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->branchA = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->branchB = DB::table('branches')->insertGetId(['name' => 'Batch Branch B', 'code' => 'BATCH-B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false, 'branch_id' => $this->branchA]);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_batch_selector_api_filters_by_product_and_branch(): void
    {
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $aAtA = $this->batch($productA->id, $this->branchA, 'A-AT-A', 10, null);
        $this->batch($productA->id, $this->branchB, 'A-AT-B', 20);
        $this->batch($productB->id, $this->branchA, 'B-AT-A', 30);

        $response = $this->getJson("/api/batch?product_id={$productA->id}&branch_id={$this->branchA}&per_page=100")
            ->assertOk();

        $this->assertSame([$aAtA], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(['A-AT-A'], collect($response->json('data'))->pluck('batch_number')->all());
        $this->assertNull($response->json('data.0.expiry_date'));
        $this->assertSame(10, $response->json('data.0.quantity'));
    }

    public function test_purchase_posting_assigns_main_branch_and_scoped_selector_returns_the_batch(): void
    {
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $provider = DB::table('providers')->insertGetId([
            'name' => 'Branch-safe purchase provider',
            'phone_num' => fake()->unique()->numerify('######'),
            'city' => 'Test',
        ]);
        $invoice = $this->postJson('/api/purchase-invoices', [
            'provider_id' => $provider,
            'branch_id' => $this->branchA,
            'date' => now()->toDateString(),
            'items' => [[
                'product_id' => $productA->id,
                'batch_number' => 'PURCHASED-IN-MAIN',
                'expire_date' => null,
                'amount' => 8,
                'purchase_price' => 2,
                'item_discount' => 0,
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/purchase-invoices/$invoice/post")->assertOk();
        $batch = DB::table('product_batches')->where('batch_number', 'PURCHASED-IN-MAIN')->first();
        $this->assertNotNull($batch);
        $this->assertSame($this->branchA, (int) $batch->branch_id);
        $this->assertDatabaseHas('stock', ['batch_id' => $batch->id, 'branch_id' => $this->branchA, 'type' => 'PURCHASE_IN', 'quantity' => 8]);

        $response = $this->getJson("/api/batch?product_id={$productA->id}&branch_id={$this->branchA}&per_page=100")
            ->assertOk();
        $this->assertSame([(int) $batch->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNull($response->json('data.0.expiry_date'));

        $this->getJson("/api/batch?product_id={$productB->id}&branch_id={$this->branchA}&per_page=100")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/batch?product_id={$productA->id}&branch_id={$this->branchB}&per_page=100")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_legacy_branchless_batches_are_main_only_and_remain_adjustable(): void
    {
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, null, 'LEGACY-MAIN-NULL-EXPIRY', 6, null);

        $main = $this->getJson("/api/batch?product_id={$product->id}&branch_id={$this->branchA}&per_page=100")
            ->assertOk();
        $this->assertSame([$batch], collect($main->json('data'))->pluck('id')->all());
        $this->assertNull($main->json('data.0.expiry_date'));

        $this->getJson("/api/batch?product_id={$product->id}&branch_id={$this->branchB}&per_page=100")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/stock-adjustments', [
            'branch_id' => $this->branchA,
            'product_id' => $product->id,
            'batch_id' => $batch,
            'quantity' => -1,
            'reason' => 'damaged',
        ])->assertCreated();
        $this->assertSame(5, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertDatabaseHas('stock', ['branch_id' => $this->branchA, 'batch_id' => $batch, 'type' => 'ADJUSTMENT_OUT', 'quantity' => 1]);
    }

    public function test_frontend_reloads_scoped_batches_and_clears_selection_on_product_or_branch_change(): void
    {
        $source = file_get_contents(base_path('../frontend/src/app/pages/FriendlyInventoryPages.tsx'));

        $this->assertStringContainsString('batchEntityLoader(Number(f.product_id || 0), Number(f.branch_id || 0))', $source);
        $this->assertStringContainsString('disabled={!f.product_id || !f.branch_id}', $source);
        $this->assertStringContainsString('setF({ ...f, branch_id: v, batch_id: "" })', $source);
        $this->assertStringContainsString('setF({ ...f, product_id: v, batch_id: "" })', $source);
        $this->assertStringNotContainsString('.listAll(', $source);
        $this->assertStringContainsString('label: x.name', $source);
        $this->assertStringNotContainsString('label: `${x.name} (${x.symbol})`', $source);
        $this->assertStringContainsString('type="number"', $source);
        $this->assertStringContainsString('dir="ltr"', $source);
    }

    public function test_backend_rejects_batch_from_another_product_atomically(): void
    {
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $batchB = $this->batch($productB->id, $this->branchA, 'B-WRONG-PRODUCT', 10);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/stock-adjustments', [
            'branch_id' => $this->branchA,
            'product_id' => $productA->id,
            'batch_id' => $batchB,
            'quantity' => 2,
            'reason' => 'found_stock',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'فشل التحقق من البيانات.')
            ->assertJsonPath('errors.batch_id.0', 'التشغيلة المحددة لا تتبع المنتج المحدد.');

        $this->assertSame(10, (int) DB::table('product_batches')->where('id', $batchB)->value('quantity'));
        $this->assertSame(0, DB::table('stock_adjustments')->count());
        $this->assertSame(0, DB::table('stock')->where('reference_type', 'stock_adjustment')->count());
    }

    public function test_backend_rejects_batch_from_another_branch(): void
    {
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, $this->branchB, 'WRONG-BRANCH', 10);

        $this->postJson('/api/stock-adjustments', [
            'branch_id' => $this->branchA,
            'product_id' => $product->id,
            'batch_id' => $batch,
            'quantity' => 2,
            'reason' => 'found_stock',
        ])->assertUnprocessable()->assertJsonPath('errors.batch_id.0', 'The selected batch is not held by the selected branch.');

        $this->assertSame(10, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertSame(0, DB::table('stock_adjustments')->count());
    }

    public function test_valid_positive_adjustment_supports_zero_stock_and_excess_reduction_is_rejected(): void
    {
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, $this->branchA, 'ZERO-STOCK', 0);
        $payload = ['branch_id' => $this->branchA, 'product_id' => $product->id, 'batch_id' => $batch, 'reason' => 'found_stock'];

        $this->postJson('/api/stock-adjustments', $payload + ['quantity' => 5])->assertCreated();
        $this->assertSame(5, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertDatabaseHas('stock_adjustments', ['branch_id' => $this->branchA, 'product_id' => $product->id, 'batch_id' => $batch, 'quantity' => 5]);
        $this->assertDatabaseHas('stock', ['branch_id' => $this->branchA, 'batch_id' => $batch, 'type' => 'ADJUSTMENT_IN', 'quantity' => 5]);

        $this->postJson('/api/stock-adjustments', $payload + ['quantity' => -6])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', 'The stock reduction exceeds the available batch quantity.');
        $this->assertSame(5, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertSame(1, DB::table('stock_adjustments')->count());
    }

    public function test_damaged_reduction_and_positive_correction_use_signed_base_quantity_and_balanced_cost_journals(): void
    {
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, $this->branchA, 'MANUAL-ACCEPTANCE-74', 74);
        $unrelatedBefore = [
            'customers' => DB::table('customers')->count(),
            'providers' => DB::table('providers')->count(),
            'sales' => DB::table('sales_invoices')->count(),
            'payments' => DB::table('payment_vouchers')->count() + DB::table('receipt_vouchers')->count(),
        ];

        $negativeId = $this->postJson('/api/stock-adjustments', [
            'branch_id' => $this->branchA,
            'product_id' => $product->id,
            'batch_id' => $batch,
            'unit_id' => null,
            'quantity' => -5,
            'reason' => 'damaged',
            'note' => null,
        ])->assertCreated()->json('data.id');

        $this->assertSame(69, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertDatabaseHas('stock_adjustments', ['id' => $negativeId, 'quantity' => -5, 'unit_cost' => 2]);
        $this->assertDatabaseHas('stock', ['batch_id' => $batch, 'branch_id' => $this->branchA, 'quantity' => 5, 'type' => 'ADJUSTMENT_OUT', 'unit_cost' => 2]);
        $this->assertJournalLine($negativeId, '5300', 'debit', 10);
        $this->assertJournalLine($negativeId, '1400', 'credit', 10);

        $positiveId = $this->postJson('/api/stock-adjustments', [
            'branch_id' => $this->branchA,
            'product_id' => $product->id,
            'batch_id' => $batch,
            'quantity' => 3,
            'reason' => 'found_stock',
        ])->assertCreated()->json('data.id');

        $this->assertSame(72, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertDatabaseHas('stock_adjustments', ['id' => $positiveId, 'quantity' => 3, 'unit_cost' => 2]);
        $this->assertDatabaseHas('stock', ['batch_id' => $batch, 'branch_id' => $this->branchA, 'quantity' => 3, 'type' => 'ADJUSTMENT_IN', 'unit_cost' => 2]);
        $this->assertJournalLine($positiveId, '1400', 'debit', 6);
        $this->assertJournalLine($positiveId, '5300', 'credit', 6);

        $this->assertSame($unrelatedBefore['customers'], DB::table('customers')->count());
        $this->assertSame($unrelatedBefore['providers'], DB::table('providers')->count());
        $this->assertSame($unrelatedBefore['sales'], DB::table('sales_invoices')->count());
        $this->assertSame($unrelatedBefore['payments'], DB::table('payment_vouchers')->count() + DB::table('receipt_vouchers')->count());
    }

    public function test_missing_inventory_adjustment_account_rolls_back_and_idempotent_chart_repair_restores_posting(): void
    {
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, $this->branchA, 'MISSING-5300', 74);
        DB::table('accounts')->where('code', '5300')->delete();
        $payload = [
            'branch_id' => $this->branchA,
            'product_id' => $product->id,
            'batch_id' => $batch,
            'quantity' => -5,
            'reason' => 'damaged',
        ];

        $this->postJson('/api/stock-adjustments', $payload)->assertServerError();
        $this->assertSame(74, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertSame(0, DB::table('stock_adjustments')->where('batch_id', $batch)->count());
        $this->assertSame(0, DB::table('stock')->where('batch_id', $batch)->where('reference_type', 'stock_adjustment')->count());
        $this->assertSame(0, DB::table('vouchers')->where('source_type', 'stock_adjustment')->count());

        $this->seed(ChartOfAccountsSeeder::class);
        $this->assertDatabaseHas('accounts', ['code' => '5300', 'name' => 'Inventory Adjustments']);
        $this->postJson('/api/stock-adjustments', $payload)->assertCreated();
        $this->assertSame(69, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
    }

    public function test_after_midnight_adjustment_uses_business_date_for_journal_and_stock_report(): void
    {
        config(['app.business_timezone' => 'Asia/Hebron']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04 21:30:00', 'UTC'));
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, $this->branchA, 'MIDNIGHT-LOCAL-DATE', 74);

        $adjustment = $this->postJson('/api/stock-adjustments', [
            'branch_id' => $this->branchA,
            'product_id' => $product->id,
            'batch_id' => $batch,
            'quantity' => -5,
            'reason' => 'damaged',
        ])->assertCreated()->json('data.id');

        $this->assertSame(69, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertDatabaseHas('stock_adjustments', ['id' => $adjustment, 'quantity' => -5, 'created_at' => '2026-09-04 21:30:00']);
        $this->assertDatabaseHas('stock', ['reference_type' => 'stock_adjustment', 'reference_id' => $adjustment, 'type' => 'ADJUSTMENT_OUT', 'quantity' => 5, 'created_at' => '2026-09-04 21:30:00']);
        $this->assertDatabaseHas('journal_entries', ['entry_date' => '2026-09-05', 'total_debit' => 10, 'total_credit' => 10]);

        $report = app(ReportExplorerService::class)->query([
            'report_key' => 'stock_movements',
            'filters' => ['branch_id' => $this->branchA, 'from' => '2026-09-05', 'to' => '2026-09-05'],
            'columns' => ['id', 'reference_id', 'type', 'created_at'],
        ], $this->admin);
        $this->assertSame([$adjustment], collect($report['rows'])->pluck('reference_id')->all());
        $this->assertSame('2026-09-05 00:30:00', $report['rows'][0]->created_at);

        $previousDay = app(ReportExplorerService::class)->query([
            'report_key' => 'stock_movements',
            'filters' => ['branch_id' => $this->branchA, 'from' => '2026-09-04', 'to' => '2026-09-04'],
            'columns' => ['id', 'reference_id', 'type'],
        ], $this->admin);
        $this->assertNotContains($adjustment, collect($previousDay['rows'])->pluck('reference_id')->all());
        $this->assertJournalLine($adjustment, '5300', 'debit', 10);
        $this->assertJournalLine($adjustment, '1400', 'credit', 10);
    }

    public function test_local_month_boundary_uses_september_open_period_not_august_utc_date(): void
    {
        config(['app.business_timezone' => 'Asia/Hebron']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31 21:30:00', 'UTC'));
        DB::table('accounting_periods')->delete();
        DB::table('accounting_periods')->insert([
            ['name' => 'August closed', 'period_year' => 2026, 'period_month' => 8, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'September open', 'period_year' => 2026, 'period_month' => 9, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $septemberPeriod = (int) DB::table('accounting_periods')->where('name', 'September open')->value('id');
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, $this->branchA, 'MONTH-BOUNDARY', 10);

        $adjustment = $this->postJson('/api/stock-adjustments', [
            'branch_id' => $this->branchA,
            'product_id' => $product->id,
            'batch_id' => $batch,
            'quantity' => -1,
            'reason' => 'damaged',
        ])->assertCreated()->json('data.id');

        $journal = DB::table('journal_entries as entry')->join('vouchers as voucher', 'voucher.id', '=', 'entry.voucher_id')->where('voucher.source_type', 'stock_adjustment')->where('voucher.source_id', $adjustment)->first(['entry.entry_date', 'entry.accounting_period_id']);
        $this->assertSame('2026-09-01', $journal->entry_date);
        $this->assertSame($septemberPeriod, (int) $journal->accounting_period_id);
    }

    private function assertJournalLine(int $adjustmentId, string $accountCode, string $side, float $amount): void
    {
        $exists = DB::table('journal_entry_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('vouchers as voucher', 'voucher.id', '=', 'entry.voucher_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('voucher.source_type', 'stock_adjustment')
            ->where('voucher.source_id', $adjustmentId)
            ->where('account.code', $accountCode)
            ->where('line.side', $side)
            ->where('line.amount', $amount)
            ->exists();

        $this->assertTrue($exists, "Missing $side journal line for account $accountCode and amount $amount.");
    }

    private function batch(int $product, ?int $branch, string $number, int $quantity, ?string $expiry = '2027-06-30'): int
    {
        return DB::table('product_batches')->insertGetId([
            'product_id' => $product,
            'branch_id' => $branch,
            'batch_number' => $number,
            'expiry_date' => $expiry,
            'quantity' => $quantity,
            'purchase_price' => 2,
            'sale_price' => 3,
            'inventory_state' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
