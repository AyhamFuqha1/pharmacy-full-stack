<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\DashBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterStabilizationTest extends TestCase
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

    public function test_cash_and_cheque_receipts_are_atomic_and_incoming_lifecycle_is_accounted(): void
    {
        $customerId = $this->customer();
        $cashId = $this->postJson('/api/receipt-vouchers', ['customer_id' => $customerId, 'amount' => 10, 'date' => now()->toDateString(), 'payment_method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.payment_method', 'cash')->json('data.id');
        $this->assertDatabaseMissing('checks', ['receipt_voucher_id' => $cashId]);
        $this->assertJournalLine('receipt', $cashId, '1100', 'debit', 10);

        $chequeReceiptId = $this->postJson('/api/receipt-vouchers', ['customer_id' => $customerId, 'amount' => 25, 'date' => now()->toDateString(), 'payment_method' => 'cheque', 'cheque_number' => 'IN-1', 'bank' => 'Test Bank', 'due_date' => now()->addDay()->toDateString()])
            ->assertCreated()->json('data.id');
        $chequeId = DB::table('checks')->where('receipt_voucher_id', $chequeReceiptId)->value('id');
        $this->assertDatabaseHas('checks', ['id' => $chequeId, 'customer_id' => $customerId, 'direction' => 'in', 'status' => 'pending']);
        $this->assertJournalLine('receipt', $chequeReceiptId, '1500', 'debit', 25);

        $balanceBeforeBounce = app(AccountingReportService::class)->customerStatement($customerId)['balance'];
        $this->postJson("/api/cheques/{$chequeId}/bounce")->assertOk()->assertJsonPath('data.status', 'bounced');
        $this->assertJournalLine('cheque_bounce', $chequeId, '1300', 'debit', 25);
        $this->assertSame($balanceBeforeBounce + 25, app(AccountingReportService::class)->customerStatement($customerId)['balance']);
        $this->postJson("/api/cheques/{$chequeId}/clear")->assertConflict();
        $clearReceiptId = $this->postJson('/api/receipt-vouchers', ['customer_id' => $customerId, 'amount' => 15, 'date' => now()->toDateString(), 'payment_method' => 'cheque', 'cheque_number' => 'IN-2', 'bank' => 'Test Bank', 'due_date' => now()->addDay()->toDateString()])->assertCreated()->json('data.id');
        $clearChequeId = DB::table('checks')->where('receipt_voucher_id', $clearReceiptId)->value('id');
        $this->postJson("/api/cheques/{$clearChequeId}/clear")->assertOk();
        $this->assertJournalLine('cheque_clear', $clearChequeId, '1200', 'debit', 15);
        $this->assertTrue(app(AccountingReportService::class)->trialBalance()['is_balanced']);
    }

    public function test_cash_and_cheque_supplier_payments_and_outgoing_lifecycle_are_accounted(): void
    {
        $providerId = $this->provider();
        $cashId = $this->postJson('/api/payment-vouchers', ['provider_id' => $providerId, 'amount' => 12, 'date' => now()->toDateString(), 'payment_method' => 'cash'])->assertCreated()->json('data.id');
        $this->assertDatabaseMissing('checks', ['payment_voucher_id' => $cashId]);
        $this->assertJournalLine('payment', $cashId, '1100', 'credit', 12);

        $paymentId = $this->postJson('/api/payment-vouchers', ['provider_id' => $providerId, 'amount' => 30, 'date' => now()->toDateString(), 'payment_method' => 'cheque', 'cheque_number' => 'OUT-1', 'bank' => 'Test Bank', 'due_date' => now()->addDay()->toDateString()])->assertCreated()->json('data.id');
        $chequeId = DB::table('checks')->where('payment_voucher_id', $paymentId)->value('id');
        $this->assertDatabaseHas('checks', ['id' => $chequeId, 'provider_id' => $providerId, 'direction' => 'out', 'status' => 'pending']);
        $this->assertJournalLine('payment', $paymentId, '2200', 'credit', 30);
        $this->postJson("/api/cheques/{$chequeId}/clear")->assertOk()->assertJsonPath('data.direction', 'outgoing');
        $this->assertJournalLine('cheque_clear', $chequeId, '1200', 'credit', 30);
        $this->postJson("/api/cheques/{$chequeId}/bounce")->assertConflict();
        $bouncePaymentId = $this->postJson('/api/payment-vouchers', ['provider_id' => $providerId, 'amount' => 18, 'date' => now()->toDateString(), 'payment_method' => 'cheque', 'cheque_number' => 'OUT-2', 'bank' => 'Test Bank', 'due_date' => now()->addDay()->toDateString()])->assertCreated()->json('data.id');
        $bounceChequeId = DB::table('checks')->where('payment_voucher_id', $bouncePaymentId)->value('id');
        $balanceBeforeBounce = app(AccountingReportService::class)->supplierStatement($providerId)['balance'];
        $this->postJson("/api/cheques/{$bounceChequeId}/bounce")->assertOk();
        $this->assertJournalLine('cheque_bounce', $bounceChequeId, '2100', 'credit', 18);
        $this->assertSame($balanceBeforeBounce + 18, app(AccountingReportService::class)->supplierStatement($providerId)['balance']);
        $this->getJson('/api/cheques?direction=outgoing&status=cleared')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.provider.id', $providerId)->assertJsonPath('data.0.payment_voucher_id', $paymentId);
    }

    public function test_cheques_can_only_be_created_through_atomic_voucher_contracts_and_filters_validate(): void
    {
        $this->postJson('/api/cheques', [])->assertStatus(405);
        $this->getJson('/api/cheques?direction=sideways')->assertUnprocessable();
        $this->getJson('/api/cheques?amount_min=20&amount_max=10')->assertUnprocessable();
    }

    public function test_balance_sheet_includes_current_earnings_and_balances(): void
    {
        [$product, $batch] = $this->inventory(10, 6);
        $invoiceId = $this->sale($product->id, 1, 100);
        $report = app(AccountingReportService::class)->balanceSheet(now()->toDateString());
        $this->assertSame(94.0, $report['current_earnings']);
        $this->assertSame($report['assets'], $report['liabilities_and_equity']);
        $this->assertTrue($report['balanced']);
    }

    public function test_dashboard_reports_net_sale_return_cancellation_and_top_quantity(): void
    {
        [$product, $batch] = $this->inventory(20, 5);
        $activeId = $this->sale($product->id, 10, 10);
        $this->postJson('/api/sales-returns', ['items' => [['invoice_id' => $activeId, 'product_id' => $product->id, 'batch_id' => $batch->id, 'quantity' => 2, 'price' => 10]]])->assertCreated();
        $cancelledId = $this->sale($product->id, 3, 10);
        $this->deleteJson("/api/sales-invoices/{$cancelledId}/cancel")->assertOk();

        $dashboard = app(DashBoardService::class)->index();
        $this->assertSame(80.0, $dashboard['todays_net_sales']);
        $this->assertSame(40.0, $dashboard['todays_net_profit']);
        $this->assertSame(8, (int) $dashboard['top_selling_products']->first()->quantity);
    }

    public function test_stock_ledger_and_product_report_use_persisted_movements_and_classify_dispensing_non_commercial(): void
    {
        [$product, $batch] = $this->inventory(7, 2);
        foreach ([['PURCHASE_IN', 10], ['SALE_OUT', 2], ['SALES_RETURN_IN', 1], ['PURCHASE_RETURN_OUT', 1], ['PRESCRIPTION_DISPENSE_OUT', 1]] as [$type, $quantity]) {
            DB::table('stock')->insert(['batch_id' => $batch->id, 'quantity' => $quantity, 'type' => $type, 'reference_type' => strtolower($type), 'reference_id' => 1, 'user_id' => $this->admin->id, 'created_at' => now()]);
        }
        $this->getJson("/api/products/{$product->id}/stock-ledger?movement_type=SALE_OUT&per_page=1")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.quantity_out', 2)->assertJsonPath('data.0.product_id', $product->id)->assertJsonPath('data.0.batch_id', $batch->id);
        $this->getJson("/api/products/{$product->id}/report")
            ->assertOk()->assertJsonPath('data.purchased_quantity', 10)->assertJsonPath('data.prescription_dispensed_quantity', 1)
            ->assertJsonPath('data.prescription_commercial_disposition', 'non_commercial')->assertJsonPath('data.current_stock', 7);
    }

    public function test_audit_and_general_ledger_expose_safe_traceability_and_notifications_are_current(): void
    {
        Product::factory()->create(['name' => 'Audited Product']);
        $audit = $this->getJson('/api/audit/reports/advanced')->assertOk();
        $audit->assertJsonPath('audit_logs.data.0.actor.id', $this->admin->id)
            ->assertJsonPath('audit_logs.data.0.entity_label', 'Product')
            ->assertJsonMissingPath('audit_logs.data.0.new_values.password');
        [$product] = $this->inventory(1, 1);
        $this->sale($product->id, 1, 10);
        $this->getJson('/api/accounting/reports/general-ledger')->assertOk()
            ->assertJsonStructure(['data' => [['source_type', 'source_id', 'source_label', 'journal_entry_id', 'entry_number']], 'meta', 'totals']);
        $alerts = $this->getJson('/api/notifications')->assertOk();
        $this->assertTrue(collect($alerts->json('data'))->every(fn ($alert) => $alert['semantics'] === 'current_actionable'));
    }

    private function assertJournalLine(string $sourceType, int $sourceId, string $accountCode, string $side, float $amount): void
    {
        $this->assertTrue(DB::table('journal_entry_lines as lines')->join('accounts', 'accounts.id', '=', 'lines.account_id')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')->where(['vouchers.source_type' => $sourceType, 'vouchers.source_id' => $sourceId, 'accounts.code' => $accountCode, 'lines.side' => $side])->where('lines.amount', $amount)->exists());
    }

    private function inventory(int $quantity, float $cost): array
    {
        $product = Product::factory()->create();
        $batch = ProductBatch::create(['product_id' => $product->id, 'batch_number' => fake()->unique()->numerify('STAB-####'), 'expiry_date' => now()->addYear(), 'quantity' => $quantity, 'purchase_price' => $cost, 'sale_price' => 10]);
        return [$product, $batch];
    }

    private function sale(int $productId, int $quantity, float $price): int
    {
        return (int) $this->postJson('/api/sales-invoices', ['customer_id' => $this->customer(), 'date' => now()->toDateString(), 'discount' => 0, 'paid_amount' => 0, 'payment_method' => 'cash', 'items' => [['product_id' => $productId, 'amount' => $quantity, 'price' => $price, 'discount' => 0, 'override_reason' => 'Accounting scenario price']]])->assertCreated()->json('data.id');
    }

    private function customer(): int { return DB::table('customers')->insertGetId(['name' => fake()->unique()->name(), 'phone_num' => fake()->unique()->numerify('######'), 'city' => 'Test']); }
    private function provider(): int { return DB::table('providers')->insertGetId(['name' => fake()->unique()->company(), 'phone_num' => fake()->unique()->numerify('######'), 'city' => 'Test']); }
}
