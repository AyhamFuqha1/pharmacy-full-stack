<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\User;
use App\Services\AccountingPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AccountingPeriod $period;
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountsSeeder::class);
        $this->user = User::factory()->create();
        $this->period = AccountingPeriod::create([
            'name' => 'August 2026',
            'period_year' => 2026,
            'period_month' => 8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $this->accounts = [];
        foreach ([
            'inventory' => '1400',
            'payable' => '2100',
            'receivable' => '1300',
            'cash' => '1100',
            'revenue' => '4100',
            'cogs' => '5100',
        ] as $key => $code) {
            $this->accounts[$key] = Account::query()->where('code', $code)->value('id');
        }
    }

    public function test_default_chart_of_accounts_is_seeded_with_hierarchy(): void
    {
        $this->assertSame('Assets', Account::query()->where('code', '1000')->value('name'));
        $this->assertSame('Cash', Account::query()->where('code', '1100')->value('name'));
        $this->assertSame('1000', Account::query()->find($this->accounts['cash'])->parent->code);
        $this->assertSame('5000', Account::query()->where('code', '5100')->first()->parent->code);
    }

    public function test_purchase_invoice_posts_inventory_against_payables(): void
    {
        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $this->providerId(),
            'user_id' => $this->user->id,
            'amount' => 250,
            'descount' => 0,
            'date' => '2026-08-10',
        ]);

        $entry = app(AccountingPostingService::class)->postPurchaseInvoice($invoiceId);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame(['debit', 'credit'], $entry->lines->pluck('side')->all());
        $this->assertSame([$this->accounts['inventory'], $this->accounts['payable']], $entry->lines->pluck('account_id')->all());
        $this->assertSame(250.0, (float) $entry->total_debit);
    }

    public function test_sales_invoice_posts_revenue_and_inventory_cost(): void
    {
        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'customer_id' => $this->customerId(),
            'user_id' => $this->user->id,
            'total_price' => 300,
            'discount' => 0,
            'profit' => 120,
            'date' => '2026-08-11',
        ]);

        $entry = app(AccountingPostingService::class)->postSalesInvoice($invoiceId);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame(480.0, (float) $entry->total_debit);
        $this->assertSame(480.0, (float) $entry->total_credit);
        $this->assertEqualsCanonicalizing([$this->accounts['receivable'], $this->accounts['cogs']], $entry->lines->where('side', 'debit')->pluck('account_id')->all());
        $this->assertEqualsCanonicalizing([$this->accounts['revenue'], $this->accounts['inventory']], $entry->lines->where('side', 'credit')->pluck('account_id')->all());
        $this->assertEqualsCanonicalizing([300.0, 300.0, 180.0, 180.0], $entry->lines->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
    }

    public function test_customer_receipt_posts_cash_against_receivables(): void
    {
        $receiptId = DB::table('receipt_vouchers')->insertGetId([
            'customer_id' => $this->customerId(),
            'user_id' => $this->user->id,
            'amount' => 75,
            'date' => '2026-08-12',
        ]);

        $entry = app(AccountingPostingService::class)->postReceiptVoucher($receiptId);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame($this->accounts['cash'], $entry->lines->firstWhere('side', 'debit')->account_id);
        $this->assertSame($this->accounts['receivable'], $entry->lines->firstWhere('side', 'credit')->account_id);
        $this->assertSame(75.0, (float) $entry->total_credit);
    }

    public function test_supplier_payment_posts_payables_against_cash(): void
    {
        $paymentId = DB::table('payment_vouchers')->insertGetId([
            'provider_id' => $this->providerId(),
            'user_id' => $this->user->id,
            'amount' => 90,
            'date' => '2026-08-13',
        ]);

        $entry = app(AccountingPostingService::class)->postPaymentVoucher($paymentId);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame($this->accounts['payable'], $entry->lines->firstWhere('side', 'debit')->account_id);
        $this->assertSame($this->accounts['cash'], $entry->lines->firstWhere('side', 'credit')->account_id);
        $this->assertSame(90.0, (float) $entry->total_debit);
    }

    public function test_purchase_return_reduces_inventory_and_payable(): void
    {
        $product = \App\Models\Product::factory()->create();
        $batch = \App\Models\ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'RETURN-PURCHASE',
            'expiry_date' => '2027-01-01',
            'quantity' => 10,
            'purchase_price' => 5,
            'sale_price' => 8,
        ]);
        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $this->providerId(), 'user_id' => $this->user->id,
            'amount' => 100, 'descount' => 0, 'date' => '2026-08-14',
        ]);
        $lineId = DB::table('prod_purch')->insertGetId([
            'purchase_invoice_id' => $invoiceId, 'batch_id' => $batch->id,
            'amount' => 10, 'price' => 5, 'discount' => 0,
        ]);
        $returnId = DB::table('purch_returns')->insertGetId([
            'invoice_id' => $invoiceId, 'invoice_item_id' => $lineId, 'batch_id' => $batch->id,
            'quantity' => 2, 'return_price' => 5, 'cost_price' => 5,
            'line_total_return' => 10, 'created_by' => $this->user->id,
            'created_at' => '2026-08-15 10:00:00', 'updated_at' => '2026-08-15 10:00:00',
        ]);

        $entry = app(AccountingPostingService::class)->postPurchaseReturn($returnId);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame($this->accounts['payable'], $entry->lines->firstWhere('side', 'debit')->account_id);
        $this->assertSame($this->accounts['inventory'], $entry->lines->firstWhere('side', 'credit')->account_id);
        $this->assertSame(10.0, (float) $entry->total_debit);
        $this->assertSame(10, $batch->fresh()->quantity);
    }

    public function test_sales_return_reverses_revenue_and_cost_without_changing_stock(): void
    {
        $product = \App\Models\Product::factory()->create();
        $batch = \App\Models\ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'RETURN-SALE',
            'expiry_date' => '2027-01-01',
            'quantity' => 6,
            'purchase_price' => 5,
            'sale_price' => 8,
        ]);
        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'customer_id' => $this->customerId(), 'user_id' => $this->user->id,
            'total_price' => 80, 'discount' => 0, 'profit' => 30, 'date' => '2026-08-15',
        ]);
        DB::table('prod_sales')->insert([
            'sales_invoice_id' => $invoiceId, 'batch_id' => $batch->id, 'amount' => 2,
            'price' => 40, 'discount' => 0, 'cost_price' => 25, 'line_total' => 80,
            'invoice_discount_share' => 0, 'final_total' => 80, 'profit' => 30,
        ]);
        DB::table('invoice_returns')->insert([
            'invoice_id' => $invoiceId, 'product_id' => $product->id, 'batch_id' => $batch->id,
            'quantity' => 1, 'price' => 40, 'reason' => 'Test return',
            'created_at' => '2026-08-15 12:00:00', 'updated_at' => '2026-08-15 12:00:00',
        ]);

        $entry = app(AccountingPostingService::class)->postSalesReturn($invoiceId);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame($this->accounts['revenue'], $entry->lines->where('side', 'debit')->firstWhere('account_id', $this->accounts['revenue'])->account_id);
        $this->assertSame($this->accounts['receivable'], $entry->lines->where('side', 'credit')->firstWhere('account_id', $this->accounts['receivable'])->account_id);
        $this->assertSame($this->accounts['inventory'], $entry->lines->where('side', 'debit')->firstWhere('account_id', $this->accounts['inventory'])->account_id);
        $this->assertSame($this->accounts['cogs'], $entry->lines->where('side', 'credit')->firstWhere('account_id', $this->accounts['cogs'])->account_id);
        $this->assertSame(65.0, (float) $entry->total_debit);
        $this->assertSame(6, $batch->fresh()->quantity);
    }

    public function test_operating_expense_credits_bank_when_paid_from_bank(): void
    {
        $expenseId = DB::table('expenses')->insertGetId([
            'user_id' => $this->user->id, 'type' => 'bank', 'amount' => 45,
            'notes' => 'Utilities', 'date' => '2026-08-16', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $entry = app(AccountingPostingService::class)->postExpense($expenseId);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame(Account::query()->where('code', '5200')->value('id'), $entry->lines->firstWhere('side', 'debit')->account_id);
        $this->assertSame(Account::query()->where('code', '1200')->value('id'), $entry->lines->firstWhere('side', 'credit')->account_id);
        $this->assertSame(45.0, (float) $entry->total_credit);
    }

    public function test_failed_posting_rolls_back_voucher_and_journal_entry(): void
    {
        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $this->providerId(),
            'user_id' => $this->user->id,
            'amount' => 100,
            'descount' => 0,
            'date' => '2026-08-10',
        ]);

        $this->period->update(['status' => 'closed']);

        $this->expectException(\RuntimeException::class);
        try {
            app(AccountingPostingService::class)->postPurchaseInvoice($invoiceId);
        } finally {
            $this->assertDatabaseCount('vouchers', 0);
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('journal_entry_lines', 0);
        }
    }

    private function customerId(): int
    {
        return DB::table('customers')->insertGetId([
            'name' => 'Test Customer',
            'phone_num' => '01000000000',
            'city' => 'Test city',
            'dept' => 0,
        ]);
    }

    private function providerId(): int
    {
        return DB::table('providers')->insertGetId([
            'name' => 'Test Provider',
            'phone_num' => '01000000001',
            'city' => 'Test city',
            'dept' => 0,
        ]);
    }
}
