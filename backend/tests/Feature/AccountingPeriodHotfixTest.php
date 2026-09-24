<?php

namespace Tests\Feature;

use App\Exceptions\AccountingPeriodException;
use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\PurchaseInvoiceService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountingPeriodHotfixTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->user = User::factory()->create();

        Route::post('/api/testing/accounting/{type}/{id}', function (string $type, int $id) {
            $posting = app(AccountingPostingService::class);

            return match ($type) {
                'purchase' => $posting->postPurchaseInvoice($id),
                'sales' => $posting->postSalesInvoice($id),
                'receipt' => $posting->postReceiptVoucher($id),
                'payment' => $posting->postPaymentVoucher($id),
            };
        });
    }

    public function test_seeder_creates_one_current_year_open_period_and_is_idempotent(): void
    {
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        $year = (int) now()->year;
        $this->assertDatabaseCount('accounting_periods', 1);
        $this->assertDatabaseHas('accounting_periods', [
            'name' => (string) $year,
            'period_year' => $year,
            'period_month' => 0,
            'start_date' => "{$year}-01-01 00:00:00",
            'end_date' => "{$year}-12-31 00:00:00",
            'status' => 'open',
        ]);
    }

    public function test_seeder_does_not_create_an_overlapping_period(): void
    {
        $year = (int) now()->year;
        AccountingPeriod::create([
            'name' => 'Existing period', 'period_year' => $year, 'period_month' => 1,
            'start_date' => "{$year}-01-01", 'end_date' => "{$year}-01-31", 'status' => 'closed',
        ]);

        $this->seed(AccountingPeriodSeeder::class);

        $this->assertDatabaseCount('accounting_periods', 1);
        $this->assertSame('closed', AccountingPeriod::first()->status);
    }

    public function test_purchase_posting_succeeds_inside_open_period(): void
    {
        $this->openPeriod('2026-01-01', '2026-12-31');
        $invoiceId = $this->purchaseInvoice('2026-08-10');

        $entry = app(AccountingPostingService::class)->postPurchaseInvoice($invoiceId);

        $this->assertTrue($entry->isBalanced());
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_outside_period_returns_standard_409_for_all_posting_contracts(): void
    {
        $ids = [
            'purchase' => $this->purchaseInvoice('2025-08-10'),
            'sales' => $this->salesInvoice('2025-08-10'),
            'receipt' => $this->receipt('2025-08-10'),
            'payment' => $this->payment('2025-08-10'),
        ];

        foreach ($ids as $type => $id) {
            $this->postJson("/api/testing/accounting/{$type}/{$id}")
                ->assertStatus(409)
                ->assertExactJson([
                    'success' => false,
                    'message' => 'No open accounting period exists for the invoice date.',
                    'errors' => [],
                ]);
        }

        $this->assertDatabaseCount('vouchers', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_entry_lines', 0);
    }

    public function test_closed_matching_period_returns_409(): void
    {
        $period = $this->openPeriod('2026-01-01', '2026-12-31');
        $period->update(['status' => 'closed']);

        $this->postJson('/api/testing/accounting/purchase/'.$this->purchaseInvoice('2026-08-10'))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No open accounting period exists for the invoice date.')
            ->assertJsonPath('errors', []);
    }

    public function test_failed_purchase_post_rolls_back_stock_status_and_journal_mutations(): void
    {
        $product = Product::factory()->create();
        $providerId = $this->providerId();
        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $providerId, 'user_id' => $this->user->id, 'amount' => 20,
            'descount' => 0, 'total_before_discount' => 20, 'total_after_discount' => 20,
            'date' => '2025-08-10', 'status' => 'draft',
        ]);
        DB::table('prod_purch')->insert([
            'purchase_invoice_id' => $invoiceId, 'product_id' => $product->id,
            'batch_number' => 'ROLLBACK-BATCH', 'amount' => 2, 'expire_date' => '2027-12-31',
            'purchase_price' => 10, 'selling_price' => 15, 'discount' => 0,
        ]);

        try {
            app(PurchaseInvoiceService::class)->post($invoiceId, $this->user);
            $this->fail('Expected accounting period failure.');
        } catch (AccountingPeriodException) {
            $this->assertDatabaseHas('purchase_invoices', ['id' => $invoiceId, 'status' => 'draft']);
            $this->assertDatabaseMissing('product_batches', ['batch_number' => 'ROLLBACK-BATCH']);
            $this->assertDatabaseCount('stock', 0);
            $this->assertDatabaseCount('vouchers', 0);
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('journal_entry_lines', 0);
        }
    }

    private function openPeriod(string $start, string $end): AccountingPeriod
    {
        return AccountingPeriod::create([
            'name' => 'Test period', 'period_year' => (int) substr($start, 0, 4), 'period_month' => 0,
            'start_date' => $start, 'end_date' => $end, 'status' => 'open',
        ]);
    }

    private function purchaseInvoice(string $date): int
    {
        return DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $this->providerId(), 'user_id' => $this->user->id,
            'amount' => 100, 'descount' => 0, 'date' => $date,
        ]);
    }

    private function salesInvoice(string $date): int
    {
        return DB::table('sales_invoices')->insertGetId([
            'customer_id' => $this->customerId(), 'user_id' => $this->user->id,
            'total_price' => 100, 'discount' => 0, 'profit' => 25, 'date' => $date,
        ]);
    }

    private function receipt(string $date): int
    {
        return DB::table('receipt_vouchers')->insertGetId([
            'customer_id' => $this->customerId(), 'user_id' => $this->user->id, 'amount' => 25, 'date' => $date,
        ]);
    }

    private function payment(string $date): int
    {
        return DB::table('payment_vouchers')->insertGetId([
            'provider_id' => $this->providerId(), 'user_id' => $this->user->id, 'amount' => 25, 'date' => $date,
        ]);
    }

    private function customerId(): int
    {
        return DB::table('customers')->insertGetId(['name' => 'Period Customer', 'phone_num' => uniqid(), 'city' => 'Test', 'dept' => 0]);
    }

    private function providerId(): int
    {
        return DB::table('providers')->insertGetId(['name' => 'Period Provider', 'phone_num' => uniqid(), 'city' => 'Test', 'dept' => 0]);
    }
}
