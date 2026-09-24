<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReturnJournalDateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_purchase_return_uses_return_date_without_changing_values_balance_or_stock_effect(): void
    {
        [$invoice, $line, $batch, $provider] = $this->purchase('2026-09-03', 10, 10);
        app(AccountingPostingService::class)->postPurchaseInvoice($invoice);
        $this->assertSame(100.0, app(AccountingReportService::class)->supplierStatement($provider)['balance']);
        Carbon::setTestNow('2026-09-04 14:28:38');

        $this->postJson("/api/purchase-invoices/{$invoice}/returns", ['items' => [[
            'invoice_item_id' => $line,
            'batch_id' => $batch,
            'quantity' => 2,
            'reasone' => 'Date regression',
        ]]])->assertCreated();

        $purchaseEntry = $this->entry('purchase_invoice', $invoice);
        $returnId = (int) DB::table('purch_returns')->where('invoice_id', $invoice)->value('id');
        $returnEntry = $this->entry('purchase_return', $returnId);
        $returnLines = DB::table('journal_entry_lines as lines')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('lines.journal_entry_id', $returnEntry->id)
            ->orderBy('lines.id')
            ->get(['accounts.code', 'lines.side', 'lines.amount']);

        $this->assertSame('2026-09-03', substr($purchaseEntry->entry_date, 0, 10));
        $this->assertSame('2026-09-04', substr($returnEntry->entry_date, 0, 10));
        $this->assertSame(['2100', '1400'], $returnLines->pluck('code')->all());
        $this->assertSame(['debit', 'credit'], $returnLines->pluck('side')->all());
        $this->assertSame([20.0, 20.0], $returnLines->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        $this->assertSame(80.0, app(AccountingReportService::class)->supplierStatement($provider)['balance']);
        $this->assertSame(8, ProductBatch::findOrFail($batch)->quantity);
    }

    public function test_cross_period_purchase_return_posts_in_return_month(): void
    {
        DB::table('accounting_periods')->delete();
        $august = AccountingPeriod::create(['name' => 'August 2026', 'period_year' => 2026, 'period_month' => 8, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'open']);
        $september = AccountingPeriod::create(['name' => 'September 2026', 'period_year' => 2026, 'period_month' => 9, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'open']);
        [$invoice, $line, $batch] = $this->purchase('2026-08-31', 10, 10);
        app(AccountingPostingService::class)->postPurchaseInvoice($invoice);
        Carbon::setTestNow('2026-09-02 09:00:00');

        $this->postJson("/api/purchase-invoices/{$invoice}/returns", ['items' => [[
            'invoice_item_id' => $line,
            'batch_id' => $batch,
            'quantity' => 2,
        ]]])->assertCreated();

        $returnId = (int) DB::table('purch_returns')->where('invoice_id', $invoice)->value('id');
        $this->assertSame((int) $august->id, (int) $this->entry('purchase_invoice', $invoice)->accounting_period_id);
        $this->assertSame('2026-09-02', substr($this->entry('purchase_return', $returnId)->entry_date, 0, 10));
        $this->assertSame((int) $september->id, (int) $this->entry('purchase_return', $returnId)->accounting_period_id);
    }

    public function test_sales_return_already_uses_sales_return_transaction_date(): void
    {
        $product = Product::factory()->create();
        $batch = ProductBatch::create(['product_id' => $product->id, 'batch_number' => 'SALE-DATE', 'expiry_date' => '2027-01-01', 'quantity' => 8, 'purchase_price' => 4, 'sale_price' => 10]);
        $customer = DB::table('customers')->insertGetId(['name' => 'Return Date Customer', 'phone_num' => '77110022', 'city' => 'Test']);
        $invoice = DB::table('sales_invoices')->insertGetId(['customer_id' => $customer, 'user_id' => $this->admin->id, 'total_price' => 20, 'discount' => 0, 'paid_amount' => 0, 'payment_method' => 'cash', 'profit' => 12, 'date' => '2026-09-03']);
        DB::table('prod_sales')->insert(['sales_invoice_id' => $invoice, 'batch_id' => $batch->id, 'amount' => 2, 'price' => 10, 'discount' => 0, 'cost_price' => 4, 'line_total' => 20, 'invoice_discount_share' => 0, 'final_total' => 20, 'profit' => 12, 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingPostingService::class)->postSalesInvoice($invoice);
        Carbon::setTestNow('2026-09-04 10:00:00');

        $this->postJson('/api/sales-returns', ['items' => [[
            'invoice_id' => $invoice,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'quantity' => 1,
            'reason' => 'Date regression',
        ]]])->assertCreated();

        $returnId = (int) DB::table('invoice_returns')->where('invoice_id', $invoice)->value('id');
        $this->assertSame('2026-09-03', substr($this->entry('sales_invoice', $invoice)->entry_date, 0, 10));
        $this->assertSame('2026-09-04', substr($this->entry('sales_return', $returnId)->entry_date, 0, 10));
    }

    private function purchase(string $date, int $quantity, float $unitCost): array
    {
        $product = Product::factory()->create();
        $batch = ProductBatch::create(['product_id' => $product->id, 'batch_number' => 'PUR-DATE-'.uniqid(), 'expiry_date' => '2027-01-01', 'quantity' => $quantity, 'purchase_price' => $unitCost, 'sale_price' => $unitCost + 2]);
        $provider = DB::table('providers')->insertGetId(['name' => 'Return Date Supplier '.uniqid(), 'phone_num' => fake()->unique()->numerify('########'), 'city' => 'Test']);
        $invoice = DB::table('purchase_invoices')->insertGetId(['provider_id' => $provider, 'user_id' => $this->admin->id, 'amount' => $quantity * $unitCost, 'total_after_discount' => $quantity * $unitCost, 'descount' => 0, 'date' => $date, 'status' => 'posted']);
        $line = DB::table('prod_purch')->insertGetId(['purchase_invoice_id' => $invoice, 'product_id' => $product->id, 'batch_id' => $batch->id, 'amount' => $quantity, 'price' => $unitCost, 'purchase_price' => $unitCost, 'discount' => 0]);

        return [$invoice, $line, $batch->id, $provider];
    }

    private function entry(string $sourceType, int $sourceId): object
    {
        return DB::table('journal_entries as entries')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->where(['vouchers.source_type' => $sourceType, 'vouchers.source_id' => $sourceId])
            ->firstOrFail(['entries.id', 'entries.entry_date', 'entries.accounting_period_id']);
    }
}
