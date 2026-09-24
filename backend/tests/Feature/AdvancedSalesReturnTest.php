<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvancedSalesReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
        DB::table('accounting_periods')->insert(['name' => 'Return Period', 'period_year' => now()->year, 'period_month' => now()->month, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_full_credit_with_restock_matches_legacy_economics(): void { $this->assertCombination(5, true); }
    public function test_reduced_credit_with_restock_posts_deduction_income(): void { $this->assertCombination(3, true); }
    public function test_full_credit_without_restock_reclassifies_historical_cost(): void { $this->assertCombination(5, false); }
    public function test_reduced_credit_without_restock_posts_fee_and_no_stock_in(): void { $this->assertCombination(3, false); }

    public function test_credit_validation_reason_and_authority_are_enforced(): void
    {
        [$invoice, $product, $batch] = $this->sale();
        $base = ['invoice_id' => $invoice, 'product_id' => $product->id, 'batch_id' => $batch->id, 'quantity' => 1];
        $this->postJson('/api/sales-returns', ['items' => [$base + ['customer_credit_unit_amount' => 6, 'return_to_inventory' => true]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.customer_credit_unit_amount')->assertJsonFragment(['Return credit cannot exceed the original returnable value.']);
        $this->postJson('/api/sales-returns', ['items' => [$base + ['customer_credit_unit_amount' => -1, 'return_to_inventory' => true]]])->assertUnprocessable();
        $this->postJson('/api/sales-returns', ['items' => [$base + ['customer_credit_unit_amount' => 3, 'return_to_inventory' => true]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.reason')->assertJsonFragment(['A reason is required when reducing the return credit.']);
        $this->postJson('/api/sales-returns', ['items' => [$base + ['customer_credit_unit_amount' => 5, 'return_to_inventory' => false]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.reason')->assertJsonFragment(['A reason is required when the item is not returned to inventory.']);

        $cashierRole = Role::query()->where('name', 'Cashier')->firstOrFail();
        $cashier = User::factory()->create(['role' => $cashierRole->id, 'force_change_password' => false]);
        $this->actingAs($cashier, 'sanctum')->postJson('/api/sales-returns', ['items' => [$base + ['customer_credit_unit_amount' => 3, 'return_to_inventory' => true, 'reason' => 'Deduction']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.customer_credit_unit_amount')->assertJsonFragment(['You are not authorized to reduce the customer return credit.']);
    }

    public function test_fully_paid_return_is_retained_as_customer_credit(): void
    {
        [$invoice, $product, $batch, $customer] = $this->sale(15);
        $this->postReturn($invoice, $product->id, $batch->id, 3, true);
        $this->assertSame(-3.0, app(AccountingReportService::class)->customerStatement($customer)['balance']);
        $this->getJson("/api/sales-invoices/{$invoice}")->assertOk()
            ->assertJsonPath('data.return_summary.net_outstanding', 0)
            ->assertJsonPath('data.return_summary.excess_settlement', 3);
    }

    public function test_mixed_restock_flags_are_independent_per_return_line(): void
    {
        [$invoice, $firstProduct, $firstBatch] = $this->sale();
        $secondProduct = Product::factory()->create(['barcode' => 'MIX-'.uniqid()]);
        $secondBatch = ProductBatch::query()->create(['product_id' => $secondProduct->id, 'batch_number' => 'MIX-'.uniqid(), 'expiry_date' => now()->addYear(), 'quantity' => 8, 'purchase_price' => 1.10, 'sale_price' => 5]);
        DB::table('prod_sales')->insert(['sales_invoice_id' => $invoice, 'batch_id' => $secondBatch->id, 'amount' => 2, 'price' => 5, 'discount' => 0, 'cost_price' => 1.10, 'line_total' => 10, 'invoice_discount_share' => 0, 'final_total' => 10, 'profit' => 7.80, 'created_at' => now(), 'updated_at' => now()]);
        $firstBefore = (int) $firstBatch->fresh()->quantity;
        $secondBefore = (int) $secondBatch->fresh()->quantity;

        $this->postJson('/api/sales-returns', ['items' => [
            ['invoice_id' => $invoice, 'product_id' => $firstProduct->id, 'batch_id' => $firstBatch->id, 'quantity' => 1, 'customer_credit_unit_amount' => 5, 'return_to_inventory' => true],
            ['invoice_id' => $invoice, 'product_id' => $secondProduct->id, 'batch_id' => $secondBatch->id, 'quantity' => 1, 'customer_credit_unit_amount' => 3, 'return_to_inventory' => false, 'reason' => 'Damaged'],
        ]])->assertCreated();

        $this->assertSame($firstBefore + 1, (int) $firstBatch->fresh()->quantity);
        $this->assertSame($secondBefore, (int) $secondBatch->fresh()->quantity);
        $this->assertSame([true, false], DB::table('invoice_returns')->where('invoice_id', $invoice)->orderBy('id')->pluck('return_to_inventory')->map(fn ($value) => (bool) $value)->all());
    }

    public function test_sales_return_print_preserves_advanced_values_and_readable_sale_reference(): void
    {
        [$invoice, $product, $batch] = $this->sale();
        $returnId = $this->postReturn($invoice, $product->id, $batch->id, 3, false);
        $saleNumber = DB::table('sales_invoices')->where('id', $invoice)->value('document_number');

        $this->getJson("/api/print-documents/sales_return/$returnId")
            ->assertOk()
            ->assertJsonPath('data.meta.title', 'Sales Return')
            ->assertJsonPath('data.content.original_sale.document_number', $saleNumber)
            ->assertJsonPath('data.content.items.0.product', $product->name)
            ->assertJsonPath('data.content.items.0.original_unit_amount', 5)
            ->assertJsonPath('data.content.items.0.gross_original_value', 5)
            ->assertJsonPath('data.content.items.0.customer_credit_value', 3)
            ->assertJsonPath('data.content.items.0.deduction', 2)
            ->assertJsonPath('data.content.items.0.return_to_inventory', false)
            ->assertJsonPath('data.content.totals.gross_original_return_total', 5)
            ->assertJsonPath('data.content.totals.customer_credit_total', 3)
            ->assertJsonPath('data.content.totals.return_deduction_total', 2)
            ->assertJsonStructure(['data' => ['meta' => ['print_identity']]]);
    }

    private function assertCombination(float $credit, bool $restock): void
    {
        [$invoice, $product, $batch, $customer] = $this->sale();
        $stockAfterSale = (int) $batch->fresh()->quantity;
        $returnId = $this->postReturn($invoice, $product->id, $batch->id, $credit, $restock);
        $this->assertSame($stockAfterSale + ($restock ? 1 : 0), (int) $batch->fresh()->quantity);
        $this->assertDatabaseHas('invoice_returns', ['id' => $returnId, 'original_returnable_unit_amount' => 5, 'customer_credit_unit_amount' => $credit, 'return_to_inventory' => $restock, 'created_by' => $this->admin->id]);
        $this->assertSame($restock ? 1 : 0, DB::table('stock')->where(['reference_type' => 'sales_return', 'reference_id' => $returnId])->count());
        $lines = $this->journal($returnId);
        $this->assertLine($lines, '4100', 'debit', 5);
        $this->assertLine($lines, '1300', 'credit', $credit);
        $this->assertLine($lines, '5100', 'credit', 1.10);
        $this->assertLine($lines, $restock ? '1400' : '5300', 'debit', 1.10);
        if ($credit < 5) $this->assertLine($lines, '4200', 'credit', 5 - $credit);
        $this->assertEquals($credit === 3.0 ? 2.0 : 0.0, max(0, app(AccountingReportService::class)->customerStatement($customer)['balance']));
        $this->getJson("/api/sales-invoices/{$invoice}")->assertOk()
            ->assertJsonPath('data.return_summary.gross_original_return_total', 5)
            ->assertJsonPath('data.return_summary.customer_credit_total', (int) $credit)
            ->assertJsonPath('data.return_summary.return_deduction_total', (int) (5 - $credit))
            ->assertJsonPath('data.return_summary.net_outstanding', $credit === 3.0 ? 2 : 0);
    }

    private function sale(float $paid = 10): array
    {
        $product = Product::factory()->create(['barcode' => 'ADV-'.uniqid()]);
        $batch = ProductBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'ADV-'.uniqid(), 'expiry_date' => now()->addYear(), 'quantity' => 10, 'purchase_price' => 1.10, 'sale_price' => 5]);
        $customer = DB::table('customers')->insertGetId(['name' => 'Advanced Return '.uniqid(), 'phone_num' => uniqid(), 'city' => 'Test']);
        $invoice = $this->postJson('/api/sales-invoices', ['customer_id' => $customer, 'date' => now()->toDateString(), 'discount' => 0, 'paid_amount' => $paid, 'payment_method' => 'cash', 'items' => [['product_id' => $product->id, 'amount' => 3, 'price' => 5, 'discount' => 0]]])->assertCreated()->json('data.id');
        return [$invoice, $product, $batch, $customer];
    }

    private function postReturn(int $invoice, int $product, int $batch, float $credit, bool $restock): int
    {
        $this->postJson('/api/sales-returns', ['items' => [['invoice_id' => $invoice, 'product_id' => $product, 'batch_id' => $batch, 'quantity' => 1, 'customer_credit_unit_amount' => $credit, 'return_to_inventory' => $restock, 'reason' => ($credit < 5 || !$restock) ? 'Controlled adjustment' : null]]])->assertCreated();
        return (int) DB::table('invoice_returns')->where('invoice_id', $invoice)->latest('id')->value('id');
    }

    private function journal(int $return): \Illuminate\Support\Collection
    {
        return DB::table('journal_entry_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('vouchers as v', 'v.id', '=', 'e.voucher_id')->where(['v.source_type' => 'sales_return', 'v.source_id' => $return])->get(['a.code', 'l.side', 'l.amount']);
    }

    private function assertLine($lines, string $code, string $side, float $amount): void
    {
        $line = $lines->first(fn ($line) => $line->code === $code && $line->side === $side);
        $this->assertNotNull($line, "$code $side journal line missing");
        $this->assertSame($amount, (float) $line->amount);
    }
}
