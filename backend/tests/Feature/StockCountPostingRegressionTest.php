<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockCountPostingRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $main;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 15:58:07', 'UTC'));
        config(['app.business_timezone' => 'Asia/Hebron']);
        $this->seed();
        $this->main = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->admin = User::factory()->create(['role' => 1, 'branch_id' => $this->main, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_negative_and_zero_lines_post_once_with_balanced_cost_accounting(): void
    {
        $product = Product::factory()->create(['name' => 'منتج الاختبار A', 'barcode' => '625202600001']);
        $batchA = $this->batch($product->id, null, '5', 74, 2, '2029-02-22');
        $batchB = $this->batch($product->id, null, 'تست 2', 40, 2.5, null);
        $unrelated = $this->unrelatedCounts();

        $count = $this->createCount($product->id);
        $this->postJson("/api/stock-counts/$count/post", ['lines' => [
            ['batch_id' => $batchA, 'counted_quantity' => 70],
            ['batch_id' => $batchB, 'counted_quantity' => 40],
        ]])->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertSame(70, (int) DB::table('product_batches')->where('id', $batchA)->value('quantity'));
        $this->assertSame(40, (int) DB::table('product_batches')->where('id', $batchB)->value('quantity'));
        $this->assertDatabaseHas('stock_count_lines', ['stock_count_id' => $count, 'batch_id' => $batchA, 'counted_quantity' => 70, 'difference' => -4]);
        $this->assertDatabaseHas('stock_count_lines', ['stock_count_id' => $count, 'batch_id' => $batchB, 'counted_quantity' => 40, 'difference' => 0]);
        $this->assertDatabaseHas('stock', ['reference_type' => 'stock_count', 'reference_id' => $count, 'batch_id' => $batchA, 'branch_id' => $this->main, 'type' => 'ADJUSTMENT_OUT', 'quantity' => 4, 'unit_cost' => 2]);
        $this->assertDatabaseMissing('stock', ['reference_type' => 'stock_count', 'reference_id' => $count, 'batch_id' => $batchB]);
        $this->assertSame(1, DB::table('stock')->where('reference_type', 'stock_count')->where('reference_id', $count)->count());
        $this->assertJournalLine($count, '5300', 'debit', 8);
        $this->assertJournalLine($count, '1400', 'credit', 8);
        $this->assertDatabaseHas('journal_entries', ['entry_date' => '2026-09-08', 'total_debit' => 8, 'total_credit' => 8]);
        $this->assertSame($unrelated, $this->unrelatedCounts());

        $this->postJson("/api/stock-counts/$count/post", ['lines' => [
            ['batch_id' => $batchA, 'counted_quantity' => 70],
            ['batch_id' => $batchB, 'counted_quantity' => 40],
        ]])->assertUnprocessable();
        $this->assertSame(70, (int) DB::table('product_batches')->where('id', $batchA)->value('quantity'));
        $this->assertSame(1, DB::table('stock')->where('reference_type', 'stock_count')->where('reference_id', $count)->count());
        $this->assertSame(1, DB::table('vouchers')->where('source_type', 'stock_count')->where('source_id', $count)->count());
    }

    public function test_positive_difference_posts_supported_in_movement_and_gain_accounting(): void
    {
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, $this->main, 'POSITIVE', 70, 2);
        $count = $this->createCount($product->id, $this->main);

        $this->postJson("/api/stock-counts/$count/post", ['lines' => [
            ['batch_id' => $batch, 'counted_quantity' => 72],
        ]])->assertOk();

        $this->assertSame(72, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertDatabaseHas('stock', ['reference_type' => 'stock_count', 'reference_id' => $count, 'type' => 'ADJUSTMENT_IN', 'quantity' => 2, 'unit_cost' => 2]);
        $this->assertJournalLine($count, '1400', 'debit', 4);
        $this->assertJournalLine($count, '5300', 'credit', 4);
    }

    public function test_foreign_batch_product_and_branch_are_rejected_without_effects(): void
    {
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $otherBranch = DB::table('branches')->insertGetId(['name' => 'Other', 'code' => 'OTHER', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $batchA = $this->batch($productA->id, $this->main, 'A-MAIN', 10, 2);
        $batchB = $this->batch($productB->id, $this->main, 'B-MAIN', 10, 2);
        $batchOther = $this->batch($productA->id, $otherBranch, 'A-OTHER', 10, 2);
        $count = $this->createCount($productA->id, $this->main);

        $this->postJson("/api/stock-counts/$count/post", ['lines' => [['batch_id' => $batchB, 'counted_quantity' => 9]]])->assertUnprocessable();
        $this->postJson("/api/stock-counts/$count/post", ['lines' => [['batch_id' => $batchOther, 'counted_quantity' => 9]]])->assertUnprocessable();

        $this->assertSame(10, (int) DB::table('product_batches')->where('id', $batchA)->value('quantity'));
        $this->assertSame(10, (int) DB::table('product_batches')->where('id', $batchB)->value('quantity'));
        $this->assertSame(10, (int) DB::table('product_batches')->where('id', $batchOther)->value('quantity'));
        $this->assertSame(0, DB::table('stock')->where('reference_type', 'stock_count')->where('reference_id', $count)->count());
        $this->assertDatabaseHas('stock_counts', ['id' => $count, 'status' => 'draft']);
    }

    public function test_accounting_failure_rolls_back_and_safe_retry_posts_once(): void
    {
        $product = Product::factory()->create();
        $batch = $this->batch($product->id, $this->main, 'ROLLBACK', 74, 2);
        $count = $this->createCount($product->id, $this->main);
        DB::table('accounts')->where('code', '5300')->delete();
        $payload = ['lines' => [['batch_id' => $batch, 'counted_quantity' => 70]]];

        $this->postJson("/api/stock-counts/$count/post", $payload)->assertServerError();
        $this->assertSame(74, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertDatabaseHas('stock_counts', ['id' => $count, 'status' => 'draft']);
        $this->assertDatabaseHas('stock_count_lines', ['stock_count_id' => $count, 'batch_id' => $batch, 'counted_quantity' => null, 'difference' => null]);
        $this->assertSame(0, DB::table('stock')->where('reference_type', 'stock_count')->where('reference_id', $count)->count());
        $this->assertSame(0, DB::table('vouchers')->where('source_type', 'stock_count')->where('source_id', $count)->count());

        $this->seed(ChartOfAccountsSeeder::class);
        $this->postJson("/api/stock-counts/$count/post", $payload)->assertOk();
        $this->assertSame(70, (int) DB::table('product_batches')->where('id', $batch)->value('quantity'));
        $this->assertSame(1, DB::table('stock')->where('reference_type', 'stock_count')->where('reference_id', $count)->count());
        $this->assertSame(1, DB::table('vouchers')->where('source_type', 'stock_count')->where('source_id', $count)->count());
    }

    private function createCount(int $product, ?int $branch = null): int
    {
        $payload = ['scope' => 'selected', 'product_ids' => [$product]];
        if ($branch !== null) {
            $payload['branch_id'] = $branch;
        }

        return (int) $this->postJson('/api/stock-counts', $payload)->assertCreated()->json('data.count.id');
    }

    private function batch(int $product, ?int $branch, string $number, int $quantity, float $cost, ?string $expiry = '2029-02-22'): int
    {
        return DB::table('product_batches')->insertGetId([
            'product_id' => $product,
            'branch_id' => $branch,
            'batch_number' => $number,
            'expiry_date' => $expiry,
            'quantity' => $quantity,
            'purchase_price' => $cost,
            'sale_price' => $cost + 1,
            'inventory_state' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertJournalLine(int $count, string $account, string $side, float $amount): void
    {
        $exists = DB::table('journal_entry_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('vouchers as voucher', 'voucher.id', '=', 'entry.voucher_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('voucher.source_type', 'stock_count')
            ->where('voucher.source_id', $count)
            ->where('account.code', $account)
            ->where('line.side', $side)
            ->where('line.amount', $amount)
            ->exists();

        $this->assertTrue($exists, "Missing $side stock-count journal line for account $account and amount $amount.");
    }

    private function unrelatedCounts(): array
    {
        return [
            'customers' => DB::table('customers')->count(),
            'providers' => DB::table('providers')->count(),
            'sales' => DB::table('sales_invoices')->count(),
            'payments' => DB::table('payment_vouchers')->count() + DB::table('receipt_vouchers')->count(),
        ];
    }
}
