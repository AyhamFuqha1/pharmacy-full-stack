<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerPaymentSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create([
            'role' => 1,
            'force_change_password' => false,
            'branch_id' => DB::table('branches')->where('code', 'MAIN')->value('id'),
        ]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_cash_receipt_allocates_thirty_against_ninety_and_posts_only_cash_and_ar(): void
    {
        [$customer, $invoice] = $this->outstandingSale(90);
        $stockBefore = (float) DB::table('product_batches')->sum('quantity');
        $this->assertSame(0, DB::table('cashier_shifts')->where('status', 'open')->count());

        $response = $this->postJson('/api/receipt-vouchers', [
            'customer_id' => $customer,
            'amount' => 30,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'allocations' => [['invoice_id' => $invoice, 'amount' => 30]],
        ])->assertCreated();
        $receipt = (int) $response->json('data.id');

        $this->assertDatabaseHas('settlement_allocations', [
            'side' => 'customer',
            'settlement_type' => 'receipt',
            'settlement_id' => $receipt,
            'invoice_type' => 'sale',
            'invoice_id' => $invoice,
            'amount' => 30,
        ]);
        $entry = DB::table('journal_entries as entries')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->where(['vouchers.source_type' => 'receipt', 'vouchers.source_id' => $receipt])
            ->value('entries.id');
        $lines = DB::table('journal_entry_lines as lines')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('lines.journal_entry_id', $entry)
            ->orderBy('lines.id')
            ->get(['accounts.code', 'lines.side', 'lines.amount']);

        $this->assertSame(['1100', '1300'], $lines->pluck('code')->all());
        $this->assertSame(['debit', 'credit'], $lines->pluck('side')->all());
        $this->assertSame([30.0, 30.0], $lines->pluck('amount')->map(fn ($value) => (float) $value)->all());
        $this->assertSame(60.0, app(AccountingReportService::class)->customerStatement($customer)['balance']);
        $this->getJson("/api/customers/{$customer}/360")
            ->assertOk()
            ->assertJsonPath('data.outstanding_invoices.0.invoice_id', $invoice)
            ->assertJsonPath('data.outstanding_invoices.0.outstanding_amount', 60);
        $this->assertDatabaseHas('cash_movements', ['source_type' => 'receipt', 'source_id' => $receipt, 'direction' => 'in', 'amount' => 30]);
        $this->assertSame($stockBefore, (float) DB::table('product_batches')->sum('quantity'));
    }

    public function test_omitted_manual_allocation_uses_existing_oldest_invoice_first_policy(): void
    {
        [$customer, $invoice] = $this->outstandingSale(90);

        $receipt = (int) $this->postJson('/api/receipt-vouchers', [
            'customer_id' => $customer,
            'amount' => 30,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('settlement_allocations', [
            'settlement_type' => 'receipt',
            'settlement_id' => $receipt,
            'invoice_id' => $invoice,
            'amount' => 30,
        ]);
    }

    public function test_receipt_rejects_allocation_above_invoice_outstanding_and_rolls_back(): void
    {
        [$customer, $invoice] = $this->outstandingSale(90);

        $this->postJson('/api/receipt-vouchers', [
            'customer_id' => $customer,
            'amount' => 100,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'allocations' => [['invoice_id' => $invoice, 'amount' => 91]],
        ])->assertUnprocessable()->assertJsonPath('errors.allocations.0', 'An allocation exceeds the selected invoice outstanding amount.');

        $this->assertSame(0, DB::table('receipt_vouchers')->count());
        $this->assertSame(0, DB::table('settlement_allocations')->count());
        $this->assertSame(0, DB::table('vouchers')->where('source_type', 'receipt')->count());
        $this->assertSame(0, DB::table('cash_movements')->where('source_type', 'receipt')->count());
    }

    public function test_receipt_rejects_allocated_total_above_payment_amount(): void
    {
        [$customer, $invoice] = $this->outstandingSale(90);

        $this->postJson('/api/receipt-vouchers', [
            'customer_id' => $customer,
            'amount' => 30,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'allocations' => [['invoice_id' => $invoice, 'amount' => 31]],
        ])->assertUnprocessable()->assertJsonPath('errors.allocations.0', 'Allocated total exceeds settlement amount.');

        $this->assertSame(0, DB::table('receipt_vouchers')->count());
        $this->assertSame(0, DB::table('settlement_allocations')->count());
    }

    public function test_receipt_rejects_invoice_owned_by_another_customer_and_localizes_error(): void
    {
        [$customer] = $this->outstandingSale(90);
        [, $otherInvoice] = $this->outstandingSale(50);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/receipt-vouchers', [
            'customer_id' => $customer,
            'amount' => 30,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'allocations' => [['invoice_id' => $otherInvoice, 'amount' => 30]],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'فشل التحقق من البيانات.')
            ->assertJsonPath('errors.allocations.0', 'يتجاوز أحد التخصيصات المبلغ المستحق للفاتورة المحددة.');

        $this->assertSame(0, DB::table('receipt_vouchers')->count());
        $this->assertSame(0, DB::table('settlement_allocations')->count());
    }

    public function test_failed_receipt_is_atomic_and_retry_creates_one_receipt(): void
    {
        [$customer, $invoice] = $this->outstandingSale(90);
        DB::table('accounting_periods')->update(['status' => 'closed']);
        $payload = [
            'customer_id' => $customer,
            'amount' => 30,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'allocations' => [['invoice_id' => $invoice, 'amount' => 30]],
        ];

        $this->postJson('/api/receipt-vouchers', $payload)->assertConflict();
        $this->assertSame(0, DB::table('receipt_vouchers')->count());
        $this->assertSame(0, DB::table('settlement_allocations')->count());
        $this->assertSame(0, DB::table('vouchers')->where('source_type', 'receipt')->count());
        DB::table('accounting_periods')->update(['status' => 'open']);

        $this->postJson('/api/receipt-vouchers', $payload)->assertCreated();
        $this->assertSame(1, DB::table('receipt_vouchers')->count());
        $this->assertSame(1, DB::table('settlement_allocations')->count());
        $this->assertSame(1, DB::table('vouchers')->where('source_type', 'receipt')->count());
        $this->assertSame(1, DB::table('cash_movements')->where('source_type', 'receipt')->count());
    }

    private function outstandingSale(float $amount): array
    {
        $customer = DB::table('customers')->insertGetId([
            'name' => 'Receipt Customer '.uniqid(),
            'phone_num' => fake()->unique()->numerify('########'),
            'city' => 'Test',
        ]);
        $invoice = DB::table('sales_invoices')->insertGetId([
            'document_number' => 'SAL-RECEIPT-'.uniqid(),
            'customer_id' => $customer,
            'user_id' => $this->admin->id,
            'total_price' => $amount,
            'discount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'cash',
            'profit' => $amount / 3,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(\App\Services\AccountingPostingService::class)->postSalesInvoice($invoice);

        return [$customer, $invoice];
    }
}
