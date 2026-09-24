<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AccountingPostingService $posting;
    private AccountingReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ChartOfAccountsSeeder::class);
        $this->user = User::factory()->create();
        AccountingPeriod::create([
            'name' => 'August 2026',
            'period_year' => 2026,
            'period_month' => 8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);
        $this->posting = app(AccountingPostingService::class);
        $this->reports = app(AccountingReportService::class);
    }

    public function test_report_totals_match_posted_journal_entries_and_trial_balance_is_balanced(): void
    {
        $this->purchaseInvoice(100);
        $this->posting->postPurchaseInvoice(1);

        $trialBalance = $this->reports->trialBalance();
        $journalDebit = (float) DB::table('journal_entry_lines')->where('side', 'debit')->sum('amount');
        $journalCredit = (float) DB::table('journal_entry_lines')->where('side', 'credit')->sum('amount');

        $this->assertSame($journalDebit, $trialBalance['total_debit']);
        $this->assertSame($journalCredit, $trialBalance['total_credit']);
        $this->assertTrue($trialBalance['is_balanced']);
        $this->assertSame(100.0, $this->reports->inventoryValuation()['value']);
    }

    public function test_customer_statement_balance_comes_from_receivable_journal_lines(): void
    {
        $customerId = $this->customerId();
        $salesId = DB::table('sales_invoices')->insertGetId([
            'customer_id' => $customerId, 'user_id' => $this->user->id,
            'total_price' => 100, 'discount' => 0, 'profit' => 40, 'date' => '2026-08-10',
        ]);
        $receiptId = DB::table('receipt_vouchers')->insertGetId([
            'customer_id' => $customerId, 'user_id' => $this->user->id,
            'amount' => 35, 'date' => '2026-08-11',
        ]);
        $this->posting->postSalesInvoice($salesId);
        $this->posting->postReceiptVoucher($receiptId);

        DB::table('customers')->where('id', $customerId)->update(['dept' => 9999]);
        $statement = $this->reports->customerStatement($customerId);

        $this->assertSame(100.0, $statement['total_debit']);
        $this->assertSame(35.0, $statement['total_credit']);
        $this->assertSame(65.0, $statement['balance']);
    }

    public function test_supplier_statement_balance_comes_from_payable_journal_lines(): void
    {
        $providerId = $this->providerId();
        $purchaseId = $this->purchaseInvoice(120, $providerId);
        $paymentId = DB::table('payment_vouchers')->insertGetId([
            'provider_id' => $providerId, 'user_id' => $this->user->id,
            'amount' => 50, 'date' => '2026-08-12',
        ]);
        $this->posting->postPurchaseInvoice($purchaseId);
        $this->posting->postPaymentVoucher($paymentId);

        DB::table('providers')->where('id', $providerId)->update(['dept' => 8888]);
        $statement = $this->reports->supplierStatement($providerId);

        $this->assertSame(50.0, $statement['total_debit']);
        $this->assertSame(120.0, $statement['total_credit']);
        $this->assertSame(70.0, $statement['balance']);
    }

    public function test_advanced_customer_statement_carries_true_balance_across_filters_and_pages(): void
    {
        $customerId = $this->customerId();
        $makeSale = function (float $amount, string $date) use ($customerId): void {
            $id = DB::table('sales_invoices')->insertGetId([
                'customer_id' => $customerId, 'user_id' => $this->user->id,
                'total_price' => $amount, 'discount' => 0, 'profit' => 0, 'date' => $date,
            ]);
            $this->posting->postSalesInvoice($id);
        };
        $makeSale(40, '2026-08-05');
        $makeSale(100, '2026-08-10');
        $receiptId = DB::table('receipt_vouchers')->insertGetId([
            'customer_id' => $customerId, 'user_id' => $this->user->id,
            'amount' => 35, 'date' => '2026-08-11',
        ]);
        $this->posting->postReceiptVoucher($receiptId);
        DB::table('vouchers')->where('source_type', 'receipt')->where('source_id', $receiptId)->update(['reference_number' => 'REC-SEARCH-35']);

        $pageOne = $this->reports->customerStatementReport($customerId, Request::create('/', 'GET', [
            'from' => '2026-08-10', 'to' => '2026-08-31', 'per_page' => 1, 'page' => 1,
        ]));
        $pageTwo = $this->reports->customerStatementReport($customerId, Request::create('/', 'GET', [
            'from' => '2026-08-10', 'to' => '2026-08-31', 'per_page' => 1, 'page' => 2,
        ]));
        $filtered = $this->reports->customerStatementReport($customerId, Request::create('/', 'GET', [
            'from' => '2026-08-10', 'to' => '2026-08-31', 'transaction_type' => 'receipt',
        ]));
        $referenceFiltered = $this->reports->customerStatementReport($customerId, Request::create('/', 'GET', [
            'from' => '2026-08-10', 'to' => '2026-08-31', 'reference' => 'SEARCH-35',
        ]));

        $this->assertSame(40.0, $pageOne['opening_balance']);
        $this->assertSame('sales_invoice', $pageOne['lines']->first()->source_type);
        $this->assertSame(140.0, $pageOne['lines']->first()->running_balance);
        $this->assertSame('receipt', $pageTwo['lines']->first()->source_type);
        $this->assertSame(105.0, $pageTwo['lines']->first()->running_balance);
        $this->assertSame(2, $pageTwo['meta']['total']);
        $this->assertSame(0.0, $filtered['total_debit']);
        $this->assertSame(35.0, $filtered['total_credit']);
        $this->assertSame(105.0, $filtered['closing_balance']);
        $this->assertSame(105.0, $filtered['lines']->first()->running_balance);
        $this->assertSame('true_account_balance', $filtered['balance_semantics']);
        $this->assertSame('REC-SEARCH-35', $referenceFiltered['lines']->first()->reference_number);
        $this->assertSame(105.0, $this->reports->customerStatement($customerId)['balance']);
    }

    public function test_advanced_supplier_statement_uses_payable_convention_and_reconciles(): void
    {
        $providerId = $this->providerId();
        $prior = $this->purchaseInvoice(120, $providerId);
        DB::table('purchase_invoices')->where('id', $prior)->update(['date' => '2026-08-05']);
        $this->posting->postPurchaseInvoice($prior);
        $periodPurchase = $this->purchaseInvoice(80, $providerId);
        DB::table('purchase_invoices')->where('id', $periodPurchase)->update(['date' => '2026-08-10']);
        $this->posting->postPurchaseInvoice($periodPurchase);
        $paymentId = DB::table('payment_vouchers')->insertGetId([
            'provider_id' => $providerId, 'user_id' => $this->user->id,
            'amount' => 20, 'date' => '2026-08-11',
        ]);
        $this->posting->postPaymentVoucher($paymentId);

        $statement = $this->reports->supplierStatementReport($providerId, Request::create('/', 'GET', [
            'from' => '2026-08-10', 'to' => '2026-08-31', 'per_page' => 1, 'page' => 2,
        ]));
        $filtered = $this->reports->supplierStatementReport($providerId, Request::create('/', 'GET', [
            'from' => '2026-08-10', 'to' => '2026-08-31', 'transaction_type' => 'payment',
        ]));

        $this->assertSame(120.0, $statement['opening_balance']);
        $this->assertSame('payment', $statement['lines']->first()->source_type);
        $this->assertSame(180.0, $statement['lines']->first()->running_balance);
        $this->assertSame(80.0, $statement['total_credit']);
        $this->assertSame(20.0, $statement['total_debit']);
        $this->assertSame(180.0, $filtered['closing_balance']);
        $this->assertSame(180.0, $filtered['lines']->first()->running_balance);
        $this->assertSame(180.0, $this->reports->supplierStatement($providerId)['balance']);
    }

    private function purchaseInvoice(float $amount, ?int $providerId = null): int
    {
        return DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $providerId ?? $this->providerId(), 'user_id' => $this->user->id,
            'amount' => $amount, 'descount' => 0, 'date' => '2026-08-09',
        ]);
    }

    private function customerId(): int
    {
        return DB::table('customers')->insertGetId(['name' => 'Report Customer', 'phone_num' => '01000000000', 'city' => 'Test', 'dept' => 0]);
    }

    private function providerId(): int
    {
        return DB::table('providers')->insertGetId(['name' => 'Report Provider', 'phone_num' => '01000000001', 'city' => 'Test', 'dept' => 0]);
    }
}
