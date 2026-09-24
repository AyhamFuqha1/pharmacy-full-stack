<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReorderPlanningBranchStockTest extends TestCase
{
    use RefreshDatabase;

    private int $main;
    private int $branchTwo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->main = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->branchTwo = DB::table('branches')->insertGetId([
            'name' => 'Branch 2', 'code' => 'BR-2', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::factory()->create([
            'role' => 1, 'branch_id' => $this->main, 'force_change_password' => false,
        ]), 'sanctum');
    }

    public function test_reorder_stock_is_branch_isolated_sellable_and_reconciles_with_product_360(): void
    {
        $product = Product::factory()->create([
            'name' => 'Product A', 'barcode' => '625202600001',
            'reorder_level' => 110, 'reorder_quantity' => 0,
        ]);
        $this->batch($product->id, $this->main, 'MAIN-DATED', 100, now()->addYear()->toDateString());
        $this->batch($product->id, null, 'LEGACY-MAIN-NO-EXPIRY', 7, null);
        $this->batch($product->id, $this->branchTwo, 'BRANCH-2', 5, now()->addYear()->toDateString());
        $this->batch($product->id, $this->main, 'EXPIRED', 50, now()->subDay()->toDateString());

        $main = $this->getJson('/api/inventory/reorder?branch_id=' . $this->main)->assertOk();
        $main->assertJsonFragment([
            'id' => $product->id,
            'current_sellable_stock' => 107,
            'reorder_level' => 110,
            'suggested_reorder_quantity' => 3,
        ]);

        $this->getJson('/api/inventory/reorder?branch_id=' . $this->branchTwo)
            ->assertOk()->assertJsonFragment([
                'id' => $product->id,
                'current_sellable_stock' => 5,
                'suggested_reorder_quantity' => 105,
            ]);

        $this->getJson('/api/inventory/reorder')->assertOk()->assertJsonMissing([
            'id' => $product->id,
        ]);

        $branchRows = collect($this->getJson('/api/products/' . $product->id . '/360')
            ->assertOk()->json('data.stock_by_branch'));
        $this->assertSame(107.0, (float) $branchRows->firstWhere('branch_id', $this->main)['quantity']);
        $this->assertSame(5.0, (float) $branchRows->firstWhere('branch_id', $this->branchTwo)['quantity']);
    }

    public function test_zero_reorder_level_does_not_qualify_at_zero_stock(): void
    {
        $product = Product::factory()->create(['reorder_level' => 0]);

        $this->getJson('/api/inventory/reorder?branch_id=' . $this->main)
            ->assertOk()->assertJsonMissing(['id' => $product->id]);
    }

    private function batch(int $productId, ?int $branchId, string $number, int $quantity, ?string $expiry): void
    {
        DB::table('product_batches')->insert([
            'product_id' => $productId, 'branch_id' => $branchId, 'batch_number' => $number,
            'expiry_date' => $expiry, 'purchase_price' => 1, 'sale_price' => 2,
            'quantity' => $quantity, 'inventory_state' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
