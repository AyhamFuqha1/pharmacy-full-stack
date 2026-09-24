<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OptionalExpiryDateTest extends TestCase
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

    public function test_purchases_with_and_without_expiry_post_normally(): void
    {
        $product = Product::factory()->create();
        $provider = $this->provider();

        $without = $this->createPurchase($provider, $product->id, 'NO-EXPIRY', null, 5, 4);
        $with = $this->createPurchase($provider, $product->id, 'DATED', now()->addYear()->toDateString(), 3, 4);

        $this->postJson("/api/purchase-invoices/$without/post")->assertOk();
        $this->postJson("/api/purchase-invoices/$with/post")->assertOk();

        $nullBatch = ProductBatch::where('batch_number', 'NO-EXPIRY')->firstOrFail();
        $datedBatch = ProductBatch::where('batch_number', 'DATED')->firstOrFail();
        $this->assertNull($nullBatch->expiry_date);
        $this->assertSame(5, $nullBatch->quantity);
        $this->assertNotNull($datedBatch->expiry_date);
        $this->assertTrue($nullBatch->isSellable());
    }

    public function test_fefo_uses_dated_stock_first_then_null_expiry_stock(): void
    {
        $product = Product::factory()->create();
        $dated = $this->batch($product->id, 'DATED-FIRST', now()->addMonths(6)->toDateString(), 5);
        $noExpiry = $this->batch($product->id, 'NULL-SECOND', null, 5);
        $customer = $this->customer();

        $invoice = $this->postJson('/api/sales-invoices', [
            'customer_id' => $customer,
            'date' => now()->toDateString(),
            'discount' => 0,
            'paid_amount' => 12,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'amount' => 6, 'price' => 2, 'discount' => 0]],
        ])->assertCreated()->json('data.id');

        $allocations = DB::table('prod_sales')->where('sales_invoice_id', $invoice)->orderBy('id')->get();
        $this->assertCount(2, $allocations);
        $this->assertSame($dated, (int) $allocations[0]->batch_id);
        $this->assertSame(5, (int) $allocations[0]->amount);
        $this->assertSame($noExpiry, (int) $allocations[1]->batch_id);
        $this->assertSame(1, (int) $allocations[1]->amount);

        $this->postJson('/api/sales-invoices', [
            'customer_id' => $customer,
            'date' => now()->toDateString(),
            'discount' => 0,
            'paid_amount' => 2,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'amount' => 1, 'price' => 2, 'discount' => 0]],
        ])->assertCreated();
        $this->assertSame(3, ProductBatch::findOrFail($noExpiry)->quantity);
    }

    public function test_null_expiry_is_ignored_by_expiry_views_and_can_be_returned(): void
    {
        $product = Product::factory()->create();
        $invoice = $this->createPurchase($this->provider(), $product->id, 'RETURN-NULL', null, 10, 3);
        $posted = $this->postJson("/api/purchase-invoices/$invoice/post")->assertOk()->json('data');
        $line = $posted['items'][0];

        $expired = $this->getJson('/api/batch?stock_status=expired&per_page=100')->assertOk();
        $this->assertNotContains($line['batch_id'], collect($expired->json('data'))->pluck('id')->all());
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/alerts/actionable')->assertOk();

        $this->postJson("/api/purchase-invoices/$invoice/returns", [
            'items' => [[
                'invoice_item_id' => $line['id'],
                'batch_id' => $line['batch_id'],
                'quantity' => 2,
                'reasone' => 'Supplier return',
            ]],
        ])->assertCreated();
        $this->assertSame(8, ProductBatch::findOrFail($line['batch_id'])->quantity);
    }

    public function test_opening_balance_and_import_accept_blank_expiry_but_reject_invalid_date(): void
    {
        $product = Product::factory()->create();
        $branch = (int) DB::table('branches')->value('id');
        $opening = $this->postJson('/api/opening-balances', [
            'branch_id' => $branch,
            'effective_date' => now()->toDateString(),
            'lines' => [[
                'type' => 'inventory',
                'product_id' => $product->id,
                'batch_number' => 'OPEN-NULL',
                'expiry_date' => null,
                'quantity' => 4,
                'unit_cost' => 2,
                'sale_price' => 3,
            ]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/opening-balances/$opening/validate")->assertOk();
        $this->postJson("/api/opening-balances/$opening/post")->assertOk();
        $this->assertDatabaseHas('product_batches', ['batch_number' => 'OPEN-NULL', 'expiry_date' => null, 'quantity' => 4]);

        Storage::fake('local');
        $validCsv = "product_id,batch_number,expiry_date,quantity,unit_cost,sale_price\n{$product->id},IMPORT-NULL,,5,2,3\n";
        $this->postJson('/api/imports/preview', [
            'domain' => 'opening_stock',
            'file' => UploadedFile::fake()->createWithContent('opening.csv', $validCsv),
        ])->assertCreated()->assertJsonPath('data.job.failed_rows', 0);

        $invalidCsv = "product_id,batch_number,expiry_date,quantity,unit_cost,sale_price\n{$product->id},IMPORT-BAD,not-a-date,5,2,3\n";
        $this->postJson('/api/imports/preview', [
            'domain' => 'opening_stock',
            'file' => UploadedFile::fake()->createWithContent('invalid.csv', $invalidCsv),
        ])->assertCreated()->assertJsonPath('data.job.failed_rows', 1);
    }

    private function createPurchase(int $provider, int $product, string $batch, ?string $expiry, int $quantity, float $cost): int
    {
        return $this->postJson('/api/purchase-invoices', [
            'provider_id' => $provider,
            'date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product,
                'batch_number' => $batch,
                'expire_date' => $expiry,
                'amount' => $quantity,
                'purchase_price' => $cost,
                'item_discount' => 0,
            ]],
        ])->assertCreated()->json('data.id');
    }

    private function batch(int $product, string $number, ?string $expiry, int $quantity): int
    {
        return DB::table('product_batches')->insertGetId([
            'product_id' => $product,
            'batch_number' => $number,
            'expiry_date' => $expiry,
            'purchase_price' => 1,
            'sale_price' => 2,
            'quantity' => $quantity,
            'inventory_state' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function provider(): int
    {
        return DB::table('providers')->insertGetId(['name' => fake()->company(), 'phone_num' => fake()->unique()->numerify('######'), 'city' => 'Test']);
    }

    private function customer(): int
    {
        return DB::table('customers')->insertGetId(['name' => fake()->name(), 'phone_num' => fake()->unique()->numerify('######'), 'city' => 'Test']);
    }
}
