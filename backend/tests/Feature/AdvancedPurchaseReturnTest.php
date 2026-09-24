<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvancedPurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
        DB::table('accounting_periods')->insert(['name' => 'Purchase Return Period', 'period_year' => now()->year, 'period_month' => now()->month, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_full_supplier_credit_removes_stock_cost_and_reduces_ap(): void
    {
        [$invoice, $line, $batch] = $this->purchase();
        $return = $this->postReturn($invoice, $line, $batch->id, 5);
        $this->assertReturn($return, $batch, 5, 0);
    }

    public function test_reduced_supplier_credit_removes_full_cost_and_posts_5300_shortfall(): void
    {
        [$invoice, $line, $batch] = $this->purchase();
        $return = $this->postReturn($invoice, $line, $batch->id, 3, 'Supplier deduction');
        $this->assertReturn($return, $batch, 3, 2);
    }

    public function test_credit_validation_and_reason_are_enforced_without_stock_changes(): void
    {
        [$invoice, $line, $batch] = $this->purchase();
        $payload = fn ($credit, $reason = null) => ['items' => [['invoice_item_id' => $line, 'batch_id' => $batch->id, 'quantity' => 1, 'supplier_credit_unit_amount' => $credit, 'reasone' => $reason]]];
        $this->postJson("/api/purchase-invoices/{$invoice}/returns", $payload(6))->assertUnprocessable()->assertJsonValidationErrors('items.0.supplier_credit_unit_amount');
        $this->postJson("/api/purchase-invoices/{$invoice}/returns", $payload(-1))->assertUnprocessable();
        $this->postJson("/api/purchase-invoices/{$invoice}/returns", $payload(3))->assertUnprocessable()->assertJsonValidationErrors('items.0.reasone');
        $this->assertSame(10, (int) $batch->fresh()->quantity);
        $this->assertSame(0, DB::table('purch_returns')->count());
    }

    public function test_previous_returns_and_purchase_unit_conversion_remain_authoritative(): void
    {
        [$invoice, $line, $batch] = $this->purchase(100, 2, 20, 5);
        $this->postJson("/api/purchase-invoices/{$invoice}/returns", ['items' => [['invoice_item_id' => $line, 'batch_id' => $batch->id, 'transaction_quantity' => 2, 'supplier_credit_unit_amount' => 2]]])->assertCreated();
        $this->assertSame(60, (int) $batch->fresh()->quantity);
        $this->assertDatabaseHas('purch_returns', ['invoice_item_id' => $line, 'quantity' => 40, 'cost_price' => 2, 'return_price' => 2, 'line_total_return' => 80]);
        $batch->update(['quantity' => 160]); // isolate cumulative invoice-return validation from stock availability
        $this->postJson("/api/purchase-invoices/{$invoice}/returns", ['items' => [['invoice_item_id' => $line, 'batch_id' => $batch->id, 'transaction_quantity' => 4, 'supplier_credit_unit_amount' => 2]]])->assertConflict();
        $this->assertSame(160, (int) $batch->fresh()->quantity);
    }

    private function assertReturn(int $return, ProductBatch $batch, float $credit, float $adjustment): void
    {
        $this->assertSame(9, (int) $batch->fresh()->quantity);
        $this->assertDatabaseHas('purch_returns', ['id' => $return, 'quantity' => 1, 'cost_price' => 5, 'return_price' => $credit, 'line_total_return' => $credit]);
        $this->assertDatabaseHas('stock', ['type' => 'PURCHASE_RETURN_OUT', 'reference_type' => 'purchase_return', 'reference_id' => $return, 'quantity' => 1]);
        $lines = DB::table('journal_entry_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('vouchers as v', 'v.id', '=', 'e.voucher_id')->where(['v.source_type' => 'purchase_return', 'v.source_id' => $return])->get(['a.code', 'l.side', 'l.amount']);
        $this->assertLine($lines, '2100', 'debit', $credit);
        $this->assertLine($lines, '1400', 'credit', 5);
        if ($adjustment > 0) $this->assertLine($lines, '5300', 'debit', $adjustment);
        $this->assertEquals((float) $lines->where('side', 'debit')->sum('amount'), (float) $lines->where('side', 'credit')->sum('amount'));
    }

    private function purchase(int $quantity = 10, float $cost = 5, float $factor = 1, float $transactionQuantity = 10): array
    {
        $product = Product::factory()->create(['barcode' => 'PRET-'.uniqid()]);
        $batch = ProductBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'PRET-'.uniqid(), 'expiry_date' => now()->addYear(), 'quantity' => $quantity, 'purchase_price' => $cost, 'sale_price' => 8]);
        $provider = DB::table('providers')->insertGetId(['name' => 'Return Supplier '.uniqid(), 'phone_num' => uniqid(), 'city' => 'Test']);
        $invoice = DB::table('purchase_invoices')->insertGetId(['document_number' => 'PUR-'.uniqid(), 'provider_id' => $provider, 'user_id' => $this->admin->id, 'amount' => $quantity * $cost, 'descount' => 0, 'invoice_discount' => 0, 'total_before_discount' => $quantity * $cost, 'total_after_discount' => $quantity * $cost, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
        $line = DB::table('prod_purch')->insertGetId(['purchase_invoice_id' => $invoice, 'product_id' => $product->id, 'batch_id' => $batch->id, 'batch_number' => $batch->batch_number, 'amount' => $quantity, 'base_quantity' => $quantity, 'transaction_quantity' => $transactionQuantity, 'conversion_factor' => $factor, 'transaction_unit_cost' => $cost * $factor, 'purchase_price' => $cost, 'selling_price' => 8, 'discount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return [$invoice, $line, $batch];
    }

    private function postReturn(int $invoice, int $line, int $batch, float $credit, ?string $reason = null): int
    {
        $this->postJson("/api/purchase-invoices/{$invoice}/returns", ['items' => [['invoice_item_id' => $line, 'batch_id' => $batch, 'quantity' => 1, 'supplier_credit_unit_amount' => $credit, 'reasone' => $reason]]])->assertCreated();
        return (int) DB::table('purch_returns')->where('invoice_id', $invoice)->latest('id')->value('id');
    }

    private function assertLine($lines, string $code, string $side, float $amount): void
    {
        $line = $lines->first(fn ($line) => $line->code === $code && $line->side === $side);
        $this->assertNotNull($line, "$code $side journal line missing");
        $this->assertSame($amount, (float) $line->amount);
    }
}
