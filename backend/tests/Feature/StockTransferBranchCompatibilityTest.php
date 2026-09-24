<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockTransferBranchCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $main;
    private int $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
        $this->main = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->destination = DB::table('branches')->insertGetId([
            'name' => 'Destination', 'code' => 'DST-T', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_legacy_branchless_batch_is_main_only_and_error_is_localized(): void
    {
        [, $batch] = $this->batch(72, 2, null);

        $this->postJson('/api/stock-transfers', $this->payload($batch->id, $this->main, $this->destination, 10))
            ->assertCreated();
        $this->assertSame(72, (int) DB::table('product_batches')->where('id', $batch->id)->value('quantity'));
        $this->assertDatabaseCount('stock', 0);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/stock-transfers', $this->payload($batch->id, $this->destination, $this->main, 10))
            ->assertUnprocessable()
            ->assertJsonPath('errors.batch_id.0', 'التشغيلة المحددة غير موجودة في الفرع المصدر.');
    }

    public function test_posting_preserves_quantity_value_and_batch_identity(): void
    {
        [$product, $batch] = $this->batch(72, 2, null, '5', '2029-02-22');
        $beforeValue = $this->inventoryValue();
        $id = $this->postJson('/api/stock-transfers', $this->payload($batch->id, $this->main, $this->destination, 10))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.product_name', $product->name)
            ->assertJsonPath('data.lines.0.batch_number', '5')
            ->json('data.transfer.id');

        $this->postJson("/api/stock-transfers/$id/send")->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->assertSame(62, (int) DB::table('product_batches')->where('id', $batch->id)->value('quantity'));
        $this->postJson("/api/stock-transfers/$id/receive")->assertOk()->assertJsonPath('data.status', 'received');

        $destinationBatch = DB::table('product_batches')->where([
            'product_id' => $product->id,
            'branch_id' => $this->destination,
            'batch_number' => '5',
        ])->first();
        $this->assertNotNull($destinationBatch);
        $this->assertSame(10, (int) $destinationBatch->quantity);
        $this->assertSame('2029-02-22', $destinationBatch->expiry_date);
        $this->assertSame(2.0, (float) $destinationBatch->purchase_price);
        $this->assertSame(72, (int) DB::table('product_batches')->where('product_id', $product->id)->sum('quantity'));
        $this->assertSame($beforeValue, $this->inventoryValue());
        $this->assertDatabaseHas('stock', ['reference_id' => $id, 'type' => 'TRANSFER_OUT', 'quantity' => 10]);
        $this->assertDatabaseHas('stock', ['reference_id' => $id, 'type' => 'TRANSFER_IN', 'quantity' => 10]);
    }

    public function test_same_branch_and_insufficient_stock_are_rejected_atomically(): void
    {
        [, $batch] = $this->batch(5, 2, $this->main);
        $this->postJson('/api/stock-transfers', $this->payload($batch->id, $this->main, $this->main, 1))
            ->assertUnprocessable();

        $this->postJson('/api/stock-transfers', $this->payload($batch->id, $this->main, $this->destination, 6))
            ->assertUnprocessable();
        $this->assertSame(5, (int) DB::table('product_batches')->where('id', $batch->id)->value('quantity'));
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('stock', 0);
    }

    public function test_tampered_product_identity_is_rejected_before_stock_changes(): void
    {
        [, $batch] = $this->batch(10, 2, $this->main);
        [$other] = $this->batch(1, 4, $this->main);
        $id = $this->postJson('/api/stock-transfers', $this->payload($batch->id, $this->main, $this->destination, 2))
            ->assertCreated()->json('data.transfer.id');
        DB::table('stock_transfer_lines')->where('transfer_id', $id)->update(['product_id' => $other->id]);

        $this->postJson("/api/stock-transfers/$id/send")->assertUnprocessable();
        $this->assertSame(10, (int) DB::table('product_batches')->where('id', $batch->id)->value('quantity'));
        $this->assertDatabaseMissing('stock', ['reference_type' => 'stock_transfer', 'reference_id' => $id]);
    }

    public function test_branch_bound_user_cannot_transfer_from_another_source(): void
    {
        [, $batch] = $this->batch(10, 2, $this->main);
        $user = User::factory()->create(['role' => User::ROLE_NORMAL_USER, 'branch_id' => $this->destination, 'force_change_password' => false]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\InventoryExpansionService::class)->createTransfer(
            $this->payload($batch->id, $this->main, $this->destination, 1),
            $user->id,
        );
    }

    private function batch(int $quantity, float $cost, ?int $branch, ?string $number = null, ?string $expiry = null): array
    {
        $product = Product::factory()->create();
        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'branch_id' => $branch,
            'batch_number' => $number ?? fake()->unique()->numerify('TR-####'),
            'expiry_date' => $expiry ?? now()->addYear()->toDateString(),
            'quantity' => $quantity,
            'purchase_price' => $cost,
            'sale_price' => 5,
        ]);
        return [$product, $batch];
    }

    private function payload(int $batch, int $source, int $destination, int $quantity): array
    {
        return [
            'source_branch_id' => $source,
            'destination_branch_id' => $destination,
            'lines' => [['batch_id' => $batch, 'quantity' => $quantity]],
        ];
    }

    private function inventoryValue(): float
    {
        return (float) DB::table('product_batches')->selectRaw('SUM(quantity * purchase_price) as value')->value('value');
    }
}
