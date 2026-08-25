<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
