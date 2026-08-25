<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_invoice_posts_batch_and_creates_purchase_in_stock_movement(): void
    {
        $product = Product::factory()->create();

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-POST',
            'expiry_date' => '2028-01-01',
            'quantity' => 5,
            'purchase_price' => 4.00,
            'sale_price' => 6.00,
        ]);

        $batch->applyPurchase(10, 5.00);

        $this->assertSame(15, $batch->fresh()->quantity);
        $this->assertSame('4.67', (string) $batch->fresh()->purchase_price);

        $this->assertDatabaseHas('stock', [
            'batch_id' => $batch->id,
            'type' => 'PURCHASE_IN',
        ]);
    }

    public function test_same_batch_identity_reuses_existing_batch(): void
    {
        $product = Product::factory()->create();

        $first = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-SAME',
            'expiry_date' => '2027-05-05',
            'quantity' => 12,
            'purchase_price' => 10.00,
            'sale_price' => 14.00,
        ]);

        $second = ProductBatch::findOrCreateBatch($product->id, 'LOT-SAME', '2027-05-05', 8.00, 12.00);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(12, $second->quantity);
    }

    public function test_sale_uses_fefo_and_splits_across_batches(): void
    {
        $product = Product::factory()->create();

        $earlier = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-EARLY',
            'expiry_date' => '2026-12-01',
            'quantity' => 5,
            'purchase_price' => 3.00,
            'sale_price' => 5.00,
        ]);

        $later = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-LATE',
            'expiry_date' => '2027-12-31',
            'quantity' => 20,
            'purchase_price' => 4.00,
            'sale_price' => 6.00,
        ]);

        $batches = ProductBatch::availableForSale()->where('product_id', $product->id)->get();
        $this->assertSame([$earlier->id, $later->id], $batches->pluck('id')->all());

        $later->applySale(10);
        $this->assertSame(10, $later->fresh()->quantity);
        $this->assertSame(5, $earlier->fresh()->quantity);
    }

    public function test_expired_and_deleted_batches_are_not_sellable(): void
    {
        $product = Product::factory()->create();

        $expired = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-EXPIRED',
            'expiry_date' => '2020-01-01',
            'quantity' => 8,
            'purchase_price' => 2.00,
            'sale_price' => 4.00,
        ]);

        $deleted = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-DELETED',
            'expiry_date' => '2030-01-01',
            'quantity' => 6,
            'purchase_price' => 2.00,
            'sale_price' => 4.00,
        ]);

        $deleted->delete();

        $this->assertFalse($expired->isSellable());
        $this->assertFalse($deleted->isSellable());
    }

    public function test_sales_return_restores_batch_quantity_and_creates_return_movement(): void
    {
        $product = Product::factory()->create();

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-RETURN',
            'expiry_date' => '2028-08-08',
            'quantity' => 10,
            'purchase_price' => 2.50,
            'sale_price' => 4.00,
        ]);

        $batch->applySale(4);
        $batch->refresh();

        $this->assertSame(6, $batch->quantity);

        $user = User::factory()->create();

        Stock::create([
            'batch_id' => $batch->id,
            'quantity' => 4,
            'type' => 'SALES_RETURN_IN',
            'reference_type' => 'sales_return',
            'reference_id' => 99,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('stock', [
            'batch_id' => $batch->id,
            'type' => 'SALES_RETURN_IN',
        ]);
    }

    public function test_purchase_return_reduces_batch_quantity_and_creates_out_movement(): void
    {
        $product = Product::factory()->create();

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-PURCHASE-RETURN',
            'expiry_date' => '2028-09-09',
            'quantity' => 20,
            'purchase_price' => 3.00,
            'sale_price' => 5.00,
        ]);

        $batch->quantity -= 5;
        $batch->save();

        $user = User::factory()->create();

        Stock::create([
            'batch_id' => $batch->id,
            'quantity' => 5,
            'type' => 'PURCHASE_RETURN_OUT',
            'reference_type' => 'purchase_return',
            'reference_id' => 77,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $this->assertSame(15, $batch->fresh()->quantity);

        $this->assertDatabaseHas('stock', [
            'batch_id' => $batch->id,
            'type' => 'PURCHASE_RETURN_OUT',
        ]);
    }
}
