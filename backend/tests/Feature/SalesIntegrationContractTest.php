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

class SalesIntegrationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        DB::table('accounting_periods')->insert([
            'name' => now()->format('F Y'),
            'period_year' => now()->year,
            'period_month' => now()->month,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cashier_can_read_products_barcode_and_customers_for_sales(): void
    {
        [$product, $batch] = $this->inventory(10);
        $customerId = $this->customer();
        $this->actingAs($this->cashier(), 'sanctum');

        $this->getJson('/api/product')->assertOk()->assertJsonFragment(['id' => $product->id]);
        $this->getJson("/api/product/{$product->id}")->assertOk()->assertJsonPath('data.id', $product->id);
        $this->getJson("/api/product/barcode/{$product->barcode}")
            ->assertOk()
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.available_batches.0.id', $batch->id);
        $this->getJson('/api/customers')->assertOk()->assertJsonFragment(['id' => $customerId]);
        $this->getJson("/api/customers/{$customerId}")->assertOk()->assertJsonPath('data.id', $customerId);
    }

    public function test_cashier_read_permissions_do_not_allow_product_or_customer_accounting_mutations(): void
    {
        [$product] = $this->inventory(10);
        $customerId = $this->customer();
        $this->actingAs($this->cashier(), 'sanctum');

        $this->postJson('/api/product', ['name' => 'Blocked', 'barcode' => 'BLOCKED'])->assertForbidden();
        $this->putJson("/api/product/{$product->id}", ['name' => 'Blocked'])->assertForbidden();
        $this->deleteJson("/api/product/{$product->id}")->assertForbidden();
        $this->postJson('/api/customers', ['name' => 'Blocked', 'phone_num' => '1'])->assertForbidden();
        $this->putJson("/api/customers/{$customerId}", ['name' => 'Blocked'])->assertForbidden();
        $this->deleteJson("/api/customers/{$customerId}")->assertForbidden();
        $this->getJson('/api/payment-vouchers')->assertForbidden();
        $this->getJson('/api/accounting/reports/trial-balance')->assertForbidden();
    }

    public function test_insufficient_stock_returns_conflict_envelope(): void
    {
        [$product] = $this->inventory(2);
        $this->actingAs($this->cashier(), 'sanctum')
            ->postJson('/api/sales-invoices', $this->salePayload($product->id, 3))
            ->assertConflict()
            ->assertJson([
                'success' => false,
                'message' => 'Insufficient stock.',
                'errors' => [],
            ]);
    }

    public function test_expired_or_unavailable_stock_returns_conflict_envelope(): void
    {
        [$product] = $this->inventory(5, now()->subDay()->toDateString());
        $this->actingAs($this->cashier(), 'sanctum')
            ->postJson('/api/sales-invoices', $this->salePayload($product->id, 1))
            ->assertConflict()
            ->assertJson([
                'success' => false,
                'message' => 'No available stock for sale item.',
                'errors' => [],
            ]);
    }

    public function test_cancellation_restores_stock_reverses_accounting_and_preserves_allocations(): void
    {
        [$product, $batch] = $this->inventory(10);
        $cashier = $this->cashier();
        $this->actingAs($cashier, 'sanctum');
        $invoiceId = $this->postJson('/api/sales-invoices', $this->salePayload($product->id, 4))
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('product_batches', ['id' => $batch->id, 'quantity' => 6]);
        $allocationId = DB::table('prod_sales')->where('sales_invoice_id', $invoiceId)->value('id');
        $this->assertNotNull($allocationId);

        $this->deleteJson("/api/sales-invoices/{$invoiceId}/cancel")->assertOk();

        $this->assertDatabaseHas('product_batches', ['id' => $batch->id, 'quantity' => 10]);
        $this->assertDatabaseHas('prod_sales', ['id' => $allocationId, 'sales_invoice_id' => $invoiceId, 'batch_id' => $batch->id, 'amount' => 4]);
        $this->assertDatabaseHas('stock', [
            'batch_id' => $batch->id,
            'quantity' => 4,
            'type' => 'SALES_RETURN_IN',
            'reference_type' => 'sales_cancel',
            'reference_id' => $invoiceId,
            'user_id' => $cashier->id,
        ]);
        $this->assertDatabaseHas('vouchers', ['source_type' => 'sales_invoice_reversal', 'source_id' => $invoiceId, 'status' => 'posted']);
        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId]);

        $this->getJson("/api/sales-invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.items.0.id', $allocationId);
    }

    public function test_unpaid_sale_receivable_returns_to_original_balance_and_statement_keeps_both_entries(): void
    {
        [$product, $batch] = $this->inventory(10);
        $customerId = $this->customer();
        $this->actingAs($this->cashier(), 'sanctum');
        $reports = app(AccountingReportService::class);
        $originalBalance = $reports->customerStatement($customerId)['balance'];

        $invoiceId = $this->postJson('/api/sales-invoices', $this->salePayload($product->id, 1, 0))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($originalBalance + 10.0, $reports->customerStatement($customerId)['balance']);
        $this->deleteJson("/api/sales-invoices/{$invoiceId}/cancel")->assertOk();

        $statement = $reports->customerStatement($customerId);
        $this->assertSame($originalBalance, $statement['balance']);
        $this->assertSame(['sales_invoice', 'sales_invoice_reversal'], $statement['lines']->pluck('source_type')->all());
        $this->assertSame(['debit', 'credit'], $statement['lines']->pluck('side')->all());
        $this->assertEqualsCanonicalizing([10.0, 10.0], $statement['lines']->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        $this->assertDatabaseHas('product_batches', ['id' => $batch->id, 'quantity' => 10]);
    }

    public function test_partially_paid_sale_cancellation_exactly_inverts_original_journal(): void
    {
        $this->assertSaleCancellationInvertsOriginalJournal(4);
    }

    public function test_fully_paid_cash_sale_cancellation_does_not_create_receivable(): void
    {
        $invoiceId = $this->assertSaleCancellationInvertsOriginalJournal(10);
        $receivableId = DB::table('accounts')->where('code', '1300')->value('id');

        $this->assertSame(0, DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->whereIn('vouchers.source_type', ['sales_invoice', 'sales_invoice_reversal'])
            ->where('vouchers.source_id', $invoiceId)
            ->where('lines.account_id', $receivableId)
            ->count());
    }

    public function test_sales_list_and_detail_include_historical_customer_product_and_batch_identity(): void
    {
        [$product, $batch] = $this->inventory(10);
        $this->actingAs($this->cashier(), 'sanctum');
        $invoiceId = $this->postJson('/api/sales-invoices', $this->salePayload($product->id, 2))->assertCreated()->json('data.id');
        $this->deleteJson("/api/sales-invoices/{$invoiceId}/cancel")->assertOk();

        $this->getJson('/api/sales-invoices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoiceId)
            ->assertJsonPath('data.0.status', 'cancelled')
            ->assertJsonPath('data.0.customer.id', $this->customerId());

        $this->getJson("/api/sales-invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Sales Customer')
            ->assertJsonPath('data.items.0.product.id', $product->id)
            ->assertJsonPath('data.items.0.product.name', $product->name)
            ->assertJsonPath('data.items.0.product.barcode', $product->barcode)
            ->assertJsonPath('data.items.0.batch.id', $batch->id)
            ->assertJsonPath('data.items.0.batch.batch_number', $batch->batch_number)
            ->assertJsonPath('data.items.0.batch.expiry_date', $batch->expiry_date->toDateString());
    }

    private function cashier(): User
    {
        $role = Role::query()->where('name', 'Cashier')->firstOrFail();
        return User::factory()->create(['role' => $role->id, 'force_change_password' => false]);
    }

    private function customer(): int
    {
        return DB::table('customers')->insertGetId(['name' => 'Sales Customer', 'phone_num' => '100', 'city' => 'Test']);
    }

    private function customerId(): int
    {
        return (int) DB::table('customers')->where('name', 'Sales Customer')->value('id');
    }

    private function inventory(int $quantity, ?string $expiry = null): array
    {
        $product = Product::factory()->create(['barcode' => 'SALE-' . fake()->unique()->numerify('######')]);
        $batch = ProductBatch::query()->create([
            'product_id' => $product->id,
            'batch_number' => 'SALE-LOT-' . fake()->unique()->numerify('####'),
            'expiry_date' => $expiry ?? now()->addYear()->toDateString(),
            'quantity' => $quantity,
            'purchase_price' => 5,
            'sale_price' => 10,
        ]);
        return [$product, $batch];
    }

    private function salePayload(int $productId, int $quantity, float $paidAmount = 0): array
    {
        $customerId = $this->customerId() ?: $this->customer();
        return [
            'customer_id' => $customerId,
            'date' => now()->toDateString(),
            'discount' => 0,
            'paid_amount' => $paidAmount,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $productId,
                'amount' => $quantity,
                'price' => 10,
                'discount' => 0,
            ]],
        ];
    }

    private function assertSaleCancellationInvertsOriginalJournal(float $paidAmount): int
    {
        [$product] = $this->inventory(10);
        $this->customer();
        $this->actingAs($this->cashier(), 'sanctum');
        $invoiceId = $this->postJson('/api/sales-invoices', $this->salePayload($product->id, 1, $paidAmount))
            ->assertCreated()
            ->json('data.id');

        $original = $this->journalLines('sales_invoice', $invoiceId);
        $this->deleteJson("/api/sales-invoices/{$invoiceId}/cancel")->assertOk();
        $reversal = $this->journalLines('sales_invoice_reversal', $invoiceId);

        $expected = $original->map(fn ($line) => [
            'account_id' => $line->account_id,
            'side' => $line->side === 'debit' ? 'credit' : 'debit',
            'amount' => (float) $line->amount,
        ])->all();
        $actual = $reversal->map(fn ($line) => [
            'account_id' => $line->account_id,
            'side' => $line->side,
            'amount' => (float) $line->amount,
        ])->all();
        $this->assertEqualsCanonicalizing($expected, $actual);

        $trialBalance = app(AccountingReportService::class)->trialBalance();
        $this->assertSame($trialBalance['total_debit'], $trialBalance['total_credit']);
        $this->assertTrue($trialBalance['is_balanced']);

        return (int) $invoiceId;
    }

    private function journalLines(string $sourceType, int $sourceId)
    {
        return DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->where('vouchers.source_type', $sourceType)
            ->where('vouchers.source_id', $sourceId)
            ->orderBy('lines.id')
            ->get(['lines.account_id', 'lines.side', 'lines.amount']);
    }
}
