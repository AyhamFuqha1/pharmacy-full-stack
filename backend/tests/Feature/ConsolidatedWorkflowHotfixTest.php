<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsolidatedWorkflowHotfixTest extends TestCase
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

    public function test_sales_return_uses_persisted_allocation_and_creates_customer_credit_without_automatic_cash_refund(): void
    {
        [$product, $batch] = $this->inventory(200);
        $customerId = $this->customer('Return Customer');
        $invoiceId = $this->postJson('/api/sales-invoices', [
            'customer_id' => $customerId,
            'date' => now()->toDateString(),
            'discount' => 0,
            'paid_amount' => 200,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'amount' => 160, 'price' => 2, 'discount' => 0]],
        ])->assertCreated()->json('data.id');

        $returnId = $this->postJson('/api/sales-returns', ['items' => [[
            'invoice_id' => $invoiceId,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'quantity' => 10,
            'price' => 99,
            'reason' => 'Regression calculation',
        ]]])->assertCreated()->json('data.invoice_id');

        $persistedReturnId = DB::table('invoice_returns')->where('invoice_id', $returnId)->value('id');
        $receivableCredit = DB::table('journal_entry_lines as lines')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->where('vouchers.source_type', 'sales_return')->where('vouchers.source_id', $persistedReturnId)
            ->where('accounts.code', '1300')->where('lines.side', 'credit')->value('lines.amount');

        $this->assertSame(20.0, (float) $receivableCredit);
        $this->assertSame(0, DB::table('journal_entry_lines as lines')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->where('vouchers.source_type', 'sales_return')->where('vouchers.source_id', $persistedReturnId)
            ->whereIn('accounts.code', ['1100', '1200'])->count());
        $this->assertDatabaseHas('invoice_returns', ['id' => $persistedReturnId, 'price' => 2.00]);
    }

    public function test_sales_and_purchase_return_history_are_paginated_and_traceable(): void
    {
        [$product, $batch] = $this->inventory(10);
        $customerId = $this->customer('History Customer');
        $providerId = $this->provider('History Supplier');
        $salesInvoiceId = $this->salesInvoice($customerId, 'cash', now()->toDateString());
        $saleLineId = DB::table('prod_sales')->insertGetId([
            'sales_invoice_id' => $salesInvoiceId, 'batch_id' => $batch->id, 'amount' => 2, 'price' => 10,
            'discount' => 0, 'cost_price' => 5, 'line_total' => 20, 'invoice_discount_share' => 0, 'final_total' => 20, 'profit' => 10,
        ]);
        $salesReturnId = DB::table('invoice_returns')->insertGetId([
            'invoice_id' => $salesInvoiceId, 'product_id' => $product->id, 'batch_id' => $batch->id,
            'quantity' => 1, 'price' => 10, 'reason' => 'History', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseInvoiceId = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $providerId, 'user_id' => $this->admin->id, 'amount' => 50, 'descount' => 0, 'date' => now()->toDateString(),
        ]);
        $purchaseLineId = DB::table('prod_purch')->insertGetId([
            'purchase_invoice_id' => $purchaseInvoiceId, 'batch_id' => $batch->id, 'product_id' => $product->id,
            'amount' => 10, 'price' => 5, 'discount' => 0,
        ]);
        $purchaseReturnId = DB::table('purch_returns')->insertGetId([
            'invoice_id' => $purchaseInvoiceId, 'invoice_item_id' => $purchaseLineId, 'batch_id' => $batch->id,
            'quantity' => 2, 'return_price' => 5, 'cost_price' => 5, 'line_total_return' => 10,
            'reason' => 'History', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_invoices')->where('id', $salesInvoiceId)->update(['document_number' => 'SAL-FILTER-100']);
        DB::table('purchase_invoices')->where('id', $purchaseInvoiceId)->update(['document_number' => 'PUR-FILTER-200']);
        DB::table('invoice_returns')->where('id', $salesReturnId)->update(['document_number' => 'SRET-FILTER-300']);
        DB::table('purch_returns')->where('id', $purchaseReturnId)->update(['document_number' => 'PRET-FILTER-400']);

        $this->getJson('/api/sales-returns?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $salesReturnId)
            ->assertJsonPath('data.0.source_invoice_id', $salesInvoiceId)->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.batch_id', $batch->id)->assertJsonPath('data.0.original_line_id', $saleLineId);
        $this->getJson("/api/sales-returns/{$salesReturnId}")->assertOk()->assertJsonPath('data.total_returned_value', 10);
        $this->getJson('/api/purchase-returns?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $purchaseReturnId)
            ->assertJsonPath('data.0.source_invoice_id', $purchaseInvoiceId)->assertJsonPath('data.0.original_line_id', $purchaseLineId);
        $this->getJson("/api/purchase-returns/{$purchaseReturnId}")->assertOk()->assertJsonPath('data.product_id', $product->id);
        $this->getJson('/api/sales-returns?return_number=SRET-FILTER&source_document_number=SAL-FILTER&customer_id='.$customerId.'&product_id='.$product->id.'&batch_search='.urlencode($batch->batch_number).'&from='.now()->toDateString().'&to='.now()->toDateString().'&user_id='.$this->admin->id.'&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $salesReturnId);
        $this->getJson('/api/purchase-returns?return_number=PRET-FILTER&source_document_number=PUR-FILTER&provider_id='.$providerId.'&product_id='.$product->id.'&batch_search='.urlencode($batch->batch_number).'&from='.now()->toDateString().'&to='.now()->toDateString().'&user_id='.$this->admin->id.'&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $purchaseReturnId);
    }

    public function test_sales_filters_run_before_pagination(): void
    {
        [$wantedProduct] = $this->inventory(10);
        [$otherProduct] = $this->inventory(10);
        $wantedCustomer = $this->customer('Needle Search');
        $otherCustomer = $this->customer('Other Customer');
        $wanted = $this->salesInvoice($wantedCustomer, 'bank', '2026-08-10');
        $other = $this->salesInvoice($otherCustomer, 'cash', '2026-07-01', now());
        DB::table('sales_invoices')->where('id', $wanted)->update(['document_number' => 'SAL-2026-000020']);
        DB::table('sales_invoices')->where('id', $other)->update(['document_number' => 'SAL-2026-000019']);
        $this->salesLine($wanted, $wantedProduct->id);
        $this->salesLine($other, $otherProduct->id);

        $this->getJson("/api/sales-invoices?invoice_id={$wanted}&per_page=1")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $wanted);
        $this->getJson('/api/sales-invoices?document_number=SAL-2026-000020')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.document_number', 'SAL-2026-000020');
        $this->getJson('/api/sales-invoices?document_number=000020')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $wanted);
        $this->getJson('/api/sales-invoices?document_number=SAL-2026')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/sales-invoices?search=Needle')->assertJsonPath('data.0.id', $wanted);
        $this->getJson("/api/sales-invoices?product_id={$wantedProduct->id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $wanted);
        $this->getJson('/api/sales-invoices?from=2026-08-01&to=2026-08-31')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $wanted);
        $this->getJson('/api/sales-invoices?status=cancelled')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $other)->assertJsonPath('data.0.cancelled', true);
        $this->getJson('/api/sales-invoices?payment_method=bank')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $wanted);
    }

    public function test_purchase_filters_run_before_pagination_and_keep_newest_first_order(): void
    {
        [$wantedProduct] = $this->inventory(10);
        [$otherProduct] = $this->inventory(10);
        $wantedProvider = $this->provider('Filtered Supplier');
        $otherProvider = $this->provider('Other Supplier');
        $older = DB::table('purchase_invoices')->insertGetId(['document_number' => 'PUR-OLD-001', 'provider_id' => $otherProvider, 'user_id' => $this->admin->id, 'amount' => 10, 'descount' => 0, 'invoice_discount' => 0, 'total_before_discount' => 10, 'total_after_discount' => 10, 'date' => '2026-07-01', 'status' => 'draft', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $wanted = DB::table('purchase_invoices')->insertGetId(['document_number' => 'PUR-NEEDLE-002', 'provider_id' => $wantedProvider, 'user_id' => $this->admin->id, 'amount' => 20, 'descount' => 0, 'invoice_discount' => 0, 'total_before_discount' => 20, 'total_after_discount' => 20, 'date' => '2026-08-10', 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prod_purch')->insert(['purchase_invoice_id' => $older, 'product_id' => $otherProduct->id, 'batch_number' => 'OLD', 'amount' => 1, 'purchase_price' => 10, 'selling_price' => 12, 'discount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prod_purch')->insert(['purchase_invoice_id' => $wanted, 'product_id' => $wantedProduct->id, 'batch_number' => 'NEEDLE', 'amount' => 1, 'purchase_price' => 20, 'selling_price' => 22, 'discount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/api/purchase-invoices?per_page=1')->assertOk()->assertJsonPath('data.0.id', $wanted);
        foreach ([
            'document_number=NEEDLE', 'provider_id='.$wantedProvider, 'product_id='.$wantedProduct->id,
            'from=2026-08-01&to=2026-08-31', 'status=posted',
        ] as $query) {
            $this->getJson('/api/purchase-invoices?'.$query.'&per_page=1&page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $wanted);
        }
    }

    public function test_soft_deleted_master_data_supports_archive_filters_detail_and_restore_without_history_mutation(): void
    {
        [$product, $batch] = $this->inventory(0);
        $customerId = $this->customer('Archive Customer');
        $providerId = $this->provider('Archive Provider');
        DB::table('stock')->insert(['batch_id' => $batch->id, 'quantity' => 3, 'type' => 'ADJUSTMENT_IN', 'reference_type' => 'test', 'reference_id' => 1, 'user_id' => $this->admin->id, 'created_at' => now()]);

        $this->deleteJson("/api/product/{$product->id}")->assertOk();
        $this->deleteJson("/api/batch/{$batch->id}")->assertOk();
        $this->deleteJson("/api/customers/{$customerId}")->assertOk();
        $this->deleteJson("/api/providers/{$providerId}")->assertOk();

        foreach ([['product', $product->id], ['batch', $batch->id], ['customers', $customerId], ['providers', $providerId]] as [$resource, $id]) {
            $this->getJson("/api/{$resource}")->assertJsonMissing(['id' => $id]);
            $this->getJson("/api/{$resource}?archive_status=archived")->assertJsonFragment(['id' => $id]);
            $this->getJson("/api/{$resource}?archive_status=all")->assertJsonFragment(['id' => $id]);
            $this->getJson("/api/{$resource}/{$id}?archive_status=archived")->assertOk()->assertJsonPath('data.id', $id);
            $this->postJson("/api/{$resource}/{$id}/restore")->assertOk()->assertJsonPath('data.id', $id);
            $this->postJson("/api/{$resource}/{$id}/restore")->assertConflict();
            $this->getJson("/api/{$resource}")->assertJsonFragment(['id' => $id]);
        }

        $this->assertDatabaseHas('product_batches', ['id' => $batch->id, 'quantity' => 0, 'deleted_at' => null]);
        $this->assertDatabaseCount('stock', 1);
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'sales-invoices') && str_contains($route->uri(), 'restore')));
    }

    private function inventory(int $quantity): array
    {
        $product = Product::factory()->create();
        $batch = ProductBatch::create(['product_id' => $product->id, 'batch_number' => fake()->unique()->numerify('LOT-####'), 'expiry_date' => now()->addYear(), 'quantity' => $quantity, 'purchase_price' => 1, 'sale_price' => 2]);
        return [$product, $batch];
    }

    private function customer(string $name): int { return DB::table('customers')->insertGetId(['name' => $name, 'phone_num' => fake()->unique()->numerify('###'), 'city' => 'Test']); }
    private function provider(string $name): int { return DB::table('providers')->insertGetId(['name' => $name, 'phone_num' => fake()->unique()->numerify('###'), 'city' => 'Test']); }

    private function salesInvoice(int $customerId, string $paymentMethod, string $date, $deletedAt = null): int
    {
        return DB::table('sales_invoices')->insertGetId(['customer_id' => $customerId, 'user_id' => $this->admin->id, 'total_price' => 10, 'discount' => 0, 'profit' => 5, 'paid_amount' => 10, 'payment_method' => $paymentMethod, 'date' => $date, 'deleted_at' => $deletedAt]);
    }

    private function salesLine(int $invoiceId, int $productId): void
    {
        $batchId = DB::table('product_batches')->where('product_id', $productId)->value('id');
        DB::table('prod_sales')->insert(['sales_invoice_id' => $invoiceId, 'batch_id' => $batchId, 'amount' => 1, 'price' => 10, 'discount' => 0, 'cost_price' => 5, 'line_total' => 10, 'invoice_discount_share' => 0, 'final_total' => 10, 'profit' => 5]);
    }
}
