<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseIntegrationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_accountant_can_read_products_needed_for_purchase_lookup(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->accountant(), 'sanctum')
            ->getJson('/api/product')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => $product->id]);

        $this->getJson("/api/product/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);
    }

    public function test_accountant_cannot_mutate_products_without_products_manage(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->accountant(), 'sanctum');

        $this->postJson('/api/product', ['name' => 'Blocked', 'barcode' => 'BLOCKED-CREATE'])
            ->assertForbidden();
        $this->putJson("/api/product/{$product->id}", ['name' => 'Blocked Update'])
            ->assertForbidden();
        $this->deleteJson("/api/product/{$product->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
        $this->assertDatabaseMissing('products', ['id' => $product->id, 'name' => 'Blocked Update']);
    }

    public function test_purchase_update_synchronizes_authoritative_and_legacy_totals(): void
    {
        $user = $this->administrator();
        $providerId = $this->provider();
        $product = Product::factory()->create();
        $invoiceId = $this->purchase($providerId, $user->id);
        $lineId = DB::table('prod_purch')->insertGetId([
            'purchase_invoice_id' => $invoiceId,
            'product_id' => $product->id,
            'batch_number' => 'OLD-LOT',
            'amount' => 1,
            'expire_date' => now()->addYear()->toDateString(),
            'purchase_price' => 1,
            'selling_price' => 2,
            'discount' => 0,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/purchase-invoices/{$invoiceId}", [
            'provider_id' => $providerId,
            'date' => now()->toDateString(),
            'invoice_discount' => 5,
            'items' => [[
                'id' => $lineId,
                'product_id' => $product->id,
                'batch_number' => 'UPDATED-LOT',
                'expire_date' => now()->addYear()->toDateString(),
                'amount' => 3,
                'purchase_price' => 10,
                'selling_price' => 15,
                'item_discount' => 2,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.total_before_discount', 28)
            ->assertJsonPath('data.total_after_discount', 23)
            ->assertJsonPath('data.amount', 23)
            ->assertJsonPath('data.descount', 5);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'total_before_discount' => 28,
            'total_after_discount' => 23,
            'amount' => 23,
            'descount' => 5,
        ]);
    }

    public function test_purchase_list_and_detail_include_provider_identity(): void
    {
        $user = $this->administrator();
        $providerId = $this->provider('Contract Supplier');
        $invoiceId = $this->purchase($providerId, $user->id);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/purchase-invoices')
            ->assertOk()
            ->assertJsonPath('data.0.provider.id', $providerId)
            ->assertJsonPath('data.0.provider.name', 'Contract Supplier');

        $this->getJson("/api/purchase-invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('data.provider.id', $providerId)
            ->assertJsonPath('data.provider.name', 'Contract Supplier');
    }

    public function test_posting_purchase_persists_batch_identity_on_invoice_line(): void
    {
        $user = $this->administrator();
        $product = Product::factory()->create();
        $providerId = $this->provider();
        $invoiceId = $this->purchase($providerId, $user->id);
        $lineId = DB::table('prod_purch')->insertGetId([
            'purchase_invoice_id' => $invoiceId,
            'product_id' => $product->id,
            'batch_number' => 'RETURNABLE-LOT',
            'amount' => 10,
            'expire_date' => now()->addYear()->toDateString(),
            'purchase_price' => 4,
            'selling_price' => 6,
            'discount' => 0,
        ]);
        DB::table('purchase_invoices')->where('id', $invoiceId)->update([
            'amount' => 40,
            'total_before_discount' => 40,
            'total_after_discount' => 40,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchase-invoices/{$invoiceId}/post")
            ->assertOk();

        $batchId = DB::table('product_batches')
            ->where('product_id', $product->id)
            ->where('batch_number', 'RETURNABLE-LOT')
            ->value('id');

        $this->assertNotNull($batchId);
        $this->assertDatabaseHas('prod_purch', [
            'id' => $lineId,
            'purchase_invoice_id' => $invoiceId,
            'batch_id' => $batchId,
        ]);
    }

    private function accountant(): User
    {
        $role = Role::query()->where('name', 'Accountant')->firstOrFail();
        return User::factory()->create(['role' => $role->id, 'force_change_password' => false]);
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => 1, 'force_change_password' => false]);
    }

    private function provider(string $name = 'Purchase Provider'): int
    {
        return DB::table('providers')->insertGetId([
            'name' => $name,
            'phone_num' => '100',
            'city' => 'Test',
        ]);
    }

    private function purchase(int $providerId, int $userId): int
    {
        return DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $providerId,
            'user_id' => $userId,
            'amount' => 0,
            'descount' => 0,
            'invoice_discount' => 0,
            'total_before_discount' => 0,
            'total_after_discount' => 0,
            'date' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }
}
