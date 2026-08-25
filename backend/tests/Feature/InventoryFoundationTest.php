<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_lot_reuses_batch_and_recalculates_weighted_average_cost(): void
    {
        $product = Product::factory()->create();

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-123',
            'expiry_date' => '2027-01-31',
            'quantity' => 10,
            'purchase_price' => 5.00,
            'sale_price' => 7.00,
        ]);

        $batch->applyPurchase(30, 6.00);

        $batch->refresh();

        $this->assertSame(40, $batch->quantity);
        $this->assertSame('5.75', (string) $batch->purchase_price);
    }

    public function test_sale_reduces_quantity_and_rejects_insufficient_stock(): void
    {
        $product = Product::factory()->create();

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-456',
            'expiry_date' => '2027-02-28',
            'quantity' => 5,
            'purchase_price' => 3.50,
            'sale_price' => 5.00,
        ]);

        $batch->applySale(4);
        $batch->refresh();

        $this->assertSame(1, $batch->quantity);

        $this->expectException(\InvalidArgumentException::class);
        $batch->applySale(2);
    }

    public function test_fefo_orders_batches_by_earliest_expiry(): void
    {
        $product = Product::factory()->create();

        $later = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-LATE',
            'expiry_date' => '2027-12-31',
            'quantity' => 20,
            'purchase_price' => 4.00,
            'sale_price' => 6.00,
        ]);

        $earlier = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-EARLY',
            'expiry_date' => '2027-01-15',
            'quantity' => 5,
            'purchase_price' => 3.00,
            'sale_price' => 5.00,
        ]);

        $batches = ProductBatch::availableForSale()->where('product_id', $product->id)->get();

        $this->assertSame([$earlier->id, $later->id], $batches->pluck('id')->all());
    }
}
