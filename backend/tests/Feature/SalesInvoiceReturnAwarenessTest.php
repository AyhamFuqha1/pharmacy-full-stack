<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\InvoiceReturnService;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesInvoiceReturnAwarenessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::factory()->create(['role' => 1, 'force_change_password' => false]);
    }

    public function test_partial_return_projects_original_returned_net_and_settlement_aware_totals(): void
    {
        [$invoice, $line, $product, $batch] = $this->sale(3, 15, 10);
        $return = $this->addReturn($invoice, $product->id, $batch->id, 1, 5);

        $document = app(SalesInvoiceService::class)->show($invoice);

        $this->assertSame(3, $document->items[0]->original_quantity);
        $this->assertSame(1, $document->items[0]->returned_quantity);
        $this->assertSame(2, $document->items[0]->net_quantity);
        $this->assertSame('partially_returned', $document->return_status);
        $this->assertSame($return, $document->linked_returns[0]->id);
        $this->assertEquals(15, $document->return_summary->original_total);
        $this->assertEquals(5, $document->return_summary->return_total);
        $this->assertEquals(10, $document->return_summary->net_total);
        $this->assertEquals(10, $document->return_summary->paid_settled);
        $this->assertEquals(0, $document->return_summary->net_outstanding);
        $this->assertDatabaseHas('prod_sales', ['id' => $line, 'amount' => 3, 'final_total' => 15]);

        $this->actingAs($this->user, 'sanctum')->getJson("/api/sales-invoices/{$invoice}")
            ->assertOk()
            ->assertJsonPath('data.items.0.original_quantity', 3)
            ->assertJsonPath('data.items.0.returned_quantity', 1)
            ->assertJsonPath('data.items.0.net_quantity', 2)
            ->assertJsonPath('data.return_status', 'partially_returned')
            ->assertJsonPath('data.linked_returns.0.id', $return)
            ->assertJsonPath('data.return_summary.net_outstanding', 0);
    }

    public function test_sales_detail_uses_linked_return_business_reference_and_rtl_safe_date(): void
    {
        $page = file_get_contents(base_path('../frontend/src/app/pages/SalesDetailsPage.tsx'));

        $this->assertStringContainsString('row.document_number || t("Sales Return")', $page);
        $this->assertStringContainsString('navigate(`/returns/sales/${row.id}`)', $page);
        $this->assertStringContainsString('<bdi dir="ltr">{formatNumericDate(row.created_at)}</bdi>', $page);
        $this->assertStringNotContainsString('t("salesReturnReference", { id: row.id })', $page);
    }

    public function test_multiple_returns_aggregate_and_full_status_requires_every_line_to_be_returned(): void
    {
        [$invoice, , $product, $batch] = $this->sale(10, 50, 0);
        $this->addReturn($invoice, $product->id, $batch->id, 2, 5);
        $this->addReturn($invoice, $product->id, $batch->id, 3, 5);
        $partial = app(SalesInvoiceService::class)->show($invoice);
        $this->assertSame(5, $partial->items[0]->returned_quantity);
        $this->assertSame(5, $partial->items[0]->net_quantity);
        $this->assertSame('partially_returned', $partial->return_status);

        $this->addReturn($invoice, $product->id, $batch->id, 5, 5);
        $full = app(SalesInvoiceService::class)->show($invoice);
        $this->assertSame(0, $full->items[0]->net_quantity);
        $this->assertSame('fully_returned', $full->return_status);

        [$otherProduct, $otherBatch] = $this->inventory();
        $this->addLine($invoice, $otherBatch->id, 2, 5);
        $multiLine = app(SalesInvoiceService::class)->show($invoice);
        $this->assertSame('partially_returned', $multiLine->return_status);
        $this->assertSame(0, $multiLine->items->firstWhere('batch_id', $batch->id)->net_quantity);
        $this->assertSame(2, $multiLine->items->firstWhere('batch_id', $otherBatch->id)->net_quantity);
    }

    public function test_prior_return_reduces_backend_authoritative_maximum(): void
    {
        [$invoice, , $product, $batch] = $this->sale(3, 15, 0);
        $this->addReturn($invoice, $product->id, $batch->id, 1, 5);
        $request = new class($invoice, $product->id, $batch->id) {
            public array $items;
            public function __construct($invoice, $product, $batch) { $this->items = [['invoice_id' => $invoice, 'product_id' => $product, 'batch_id' => $batch, 'quantity' => 3]]; }
        };

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Returned quantity exceeds sold quantity.');
        app(InvoiceReturnService::class)->store($request, $this->user);
    }

    public function test_batch_identity_keeps_same_product_returns_on_the_correct_line(): void
    {
        [$invoice, , $product, $firstBatch] = $this->sale(3, 15, 0);
        $secondBatch = ProductBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'SECOND', 'expiry_date' => now()->addYear(), 'quantity' => 10, 'purchase_price' => 2, 'sale_price' => 7]);
        $this->addLine($invoice, $secondBatch->id, 4, 28);
        $this->addReturn($invoice, $product->id, $secondBatch->id, 2, 7);

        $document = app(SalesInvoiceService::class)->show($invoice);
        $this->assertSame(0, $document->items->firstWhere('batch_id', $firstBatch->id)->returned_quantity);
        $this->assertSame(2, $document->items->firstWhere('batch_id', $secondBatch->id)->returned_quantity);
    }

    private function sale(int $quantity, float $total, float $paid): array
    {
        [$product, $batch] = $this->inventory();
        $customer = DB::table('customers')->insertGetId(['name' => 'Return Customer '.uniqid(), 'phone_num' => uniqid(), 'city' => 'Test']);
        $invoice = DB::table('sales_invoices')->insertGetId(['document_number' => 'SAL-'.uniqid(), 'customer_id' => $customer, 'user_id' => $this->user->id, 'total_price' => $total, 'discount' => 0, 'profit' => $total / 2, 'paid_amount' => $paid, 'payment_method' => 'cash', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $line = $this->addLine($invoice, $batch->id, $quantity, $total);
        return [$invoice, $line, $product, $batch];
    }

    private function inventory(): array
    {
        $product = Product::factory()->create(['barcode' => 'RET-'.uniqid()]);
        $batch = ProductBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'LOT-'.uniqid(), 'expiry_date' => now()->addYear(), 'quantity' => 10, 'purchase_price' => 2, 'sale_price' => 5]);
        return [$product, $batch];
    }

    private function addLine(int $invoice, int $batch, int $quantity, float $total): int
    {
        return DB::table('prod_sales')->insertGetId(['sales_invoice_id' => $invoice, 'batch_id' => $batch, 'amount' => $quantity, 'price' => $total / $quantity, 'discount' => 0, 'cost_price' => 2, 'line_total' => $total, 'invoice_discount_share' => 0, 'final_total' => $total, 'profit' => $total - (2 * $quantity), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function addReturn(int $invoice, int $product, int $batch, int $quantity, float $price): int
    {
        return DB::table('invoice_returns')->insertGetId(['document_number' => 'SRET-'.uniqid(), 'invoice_id' => $invoice, 'product_id' => $product, 'batch_id' => $batch, 'quantity' => $quantity, 'price' => $price, 'created_at' => now(), 'updated_at' => now()]);
    }
}
