<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedUser()
    {
        $user = \App\Models\User::factory()->create([
            'role' => 1,
            'password' => Hash::make('StrongPass123!'),
            'force_change_password' => false,
        ]);

        return $this->actingAs($user, 'sanctum');
    }

    public function test_authenticated_user_can_list_products(): void
    {
        $this->authenticatedUser();

        Product::factory()->create([
            'name' => 'Paracetamol',
            'barcode' => 'P-1001',
            'nots' => 'Pain relief',
        ]);

        $response = $this->getJson('/api/product');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Paracetamol']);
    }

    public function test_unauthenticated_user_cannot_access_product_api(): void
    {
        $response = $this->getJson('/api/product');

        $response->assertUnauthorized();
    }

    public function test_list_does_not_include_soft_deleted_products(): void
    {
        $this->authenticatedUser();

        Product::factory()->create(['name' => 'Active', 'barcode' => 'ACTIVE-1']);
        Product::factory()->create(['name' => 'Deleted', 'barcode' => 'DELETED-1'])->delete();

        $response = $this->getJson('/api/product');

        $response->assertOk();
        $response->assertJsonMissing(['name' => 'Deleted']);
        $this->assertCount(1, Product::query()->whereNull('deleted_at')->get());
    }

    public function test_show_returns_existing_product(): void
    {
        $this->authenticatedUser();

        $product = Product::factory()->create([
            'name' => 'Amoxicillin',
            'barcode' => 'AMOX-1',
        ]);

        $response = $this->getJson('/api/product/' . $product->id);

        $response->assertOk();
        $response->assertJsonPath('success', true)->assertJsonPath('data.id', $product->id);
    }

    public function test_show_returns_not_found_for_missing_product(): void
    {
        $this->authenticatedUser();

        $response = $this->getJson('/api/product/999999');

        $response->assertNotFound();
        $response->assertJson(['success' => false, 'message' => 'Product not found.']);
    }

    public function test_show_does_not_return_soft_deleted_product(): void
    {
        $this->authenticatedUser();

        $product = Product::factory()->create(['barcode' => 'SOFT-DEL-1']);
        $product->delete();

        $response = $this->getJson('/api/product/' . $product->id);

        $response->assertNotFound();
        $response->assertJson(['success' => false, 'message' => 'Product not found.']);
    }

    public function test_valid_product_creation_succeeds(): void
    {
        $this->authenticatedUser();

        $response = $this->postJson('/api/product', [
            'name' => 'Vitamin C',
            'barcode' => 'VITC-001',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Vitamin C');
        $this->assertDatabaseHas('products', ['name' => 'Vitamin C', 'barcode' => 'VITC-001']);
    }

    public function test_reorder_level_is_created_updated_reloaded_and_used_by_reorder_planning(): void
    {
        $this->authenticatedUser();

        $created = $this->postJson('/api/product', [
            'name' => 'Product A',
            'barcode' => 'REORDER-A',
            'reorder_level' => 10.125,
        ])->assertCreated()->assertJsonPath('data.reorder_level', 10.125);

        $productId = $created->json('data.id');
        $this->putJson('/api/product/' . $productId, [
            'reorder_level' => 110,
        ])->assertOk()->assertJsonPath('data.reorder_level', 110);

        $this->getJson('/api/product/' . $productId)
            ->assertOk()
            ->assertJsonPath('data.reorder_level', 110);

        $branchId = \Illuminate\Support\Facades\DB::table('branches')->insertGetId([
            'name' => 'Main',
            'code' => 'MAIN',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('product_batches')->insert([
            'product_id' => $productId,
            'branch_id' => $branchId,
            'batch_number' => 'REORDER-A-MAIN',
            'expiry_date' => now()->addYear()->toDateString(),
            'purchase_price' => 1,
            'sale_price' => 2,
            'quantity' => 107,
            'inventory_state' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/inventory/reorder?branch_id=' . $branchId)
            ->assertOk()
            ->assertJsonFragment([
                'id' => $productId,
                'name' => 'Product A',
                'current_sellable_stock' => 107,
                'reorder_level' => 110,
            ]);
    }

    public function test_reorder_level_rejects_negative_and_excess_precision_values(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/product', [
            'name' => 'Negative threshold',
            'barcode' => 'REORDER-NEG',
            'reorder_level' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors('reorder_level');

        $this->postJson('/api/product', [
            'name' => 'Precise threshold',
            'barcode' => 'REORDER-PRECISE',
            'reorder_level' => 1.2345,
        ])->assertUnprocessable()->assertJsonValidationErrors('reorder_level');
    }

    public function test_creation_validation_rejects_missing_name(): void
    {
        $this->authenticatedUser();

        $response = $this->postJson('/api/product', [
            'barcode' => 'NO-NAME-1',
        ]);

        $response->assertStatus(422);
    }

    public function test_barcode_uniqueness_is_enforced(): void
    {
        $this->authenticatedUser();

        Product::factory()->create(['barcode' => 'UNIQUE-1']);

        $response = $this->postJson('/api/product', [
            'name' => 'Another Product',
            'barcode' => 'UNIQUE-1',
        ]);

        $response->assertStatus(422);
    }

    public function test_created_response_contains_persisted_product_id_and_values(): void
    {
        $this->authenticatedUser();

        $response = $this->postJson('/api/product', [
            'name' => 'Aspirin',
            'barcode' => 'ASP-100',
            'nots' => 'Headache relief',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Aspirin');
        $response->assertJsonPath('data.barcode', 'ASP-100');
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_update_existing_product_succeeds(): void
    {
        $this->authenticatedUser();

        $product = Product::factory()->create([
            'name' => 'Old Name',
            'barcode' => 'OLD-BAR-1',
            'nots' => 'Old notes',
        ]);

        $response = $this->putJson('/api/product/' . $product->id, [
            'name' => 'Updated Name',
            'barcode' => 'UPDATED-BAR-1',
            'nots' => 'Updated notes',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Updated Name', 'barcode' => 'UPDATED-BAR-1']);
    }

    public function test_update_does_not_modify_soft_deleted_product(): void
    {
        $this->authenticatedUser();

        $product = Product::factory()->create(['barcode' => 'SOFT-UPDATE-1']);
        $product->delete();

        $response = $this->putJson('/api/product/' . $product->id, [
            'name' => 'Should Not Update',
            'barcode' => 'SHOULD-NOT-UPDATE',
        ]);

        $response->assertNotFound();
    }

    public function test_update_barcode_validation_uses_current_value(): void
    {
        $this->authenticatedUser();

        $product = Product::factory()->create(['barcode' => 'CURRENT-1']);

        $response = $this->putJson('/api/product/' . $product->id, [
            'barcode' => 'CURRENT-1',
        ]);

        $response->assertOk();
    }

    public function test_delete_performs_soft_delete(): void
    {
        $this->authenticatedUser();

        $product = Product::factory()->create(['barcode' => 'DELETE-1']);

        $response = $this->deleteJson('/api/product/' . $product->id);

        $response->assertOk();
        $this->assertNotNull(Product::withTrashed()->find($product->id)->deleted_at);
        $this->assertCount(0, Product::query()->whereNull('deleted_at')->get());
    }

    public function test_deleted_product_no_longer_appears_in_list(): void
    {
        $this->authenticatedUser();

        Product::factory()->create(['barcode' => 'KEEP-1']);
        $deleted = Product::factory()->create(['barcode' => 'DELETE-2']);
        $deleted->delete();

        $response = $this->getJson('/api/product');

        $response->assertOk();
        $response->assertJsonMissing(['barcode' => 'DELETE-2']);
    }

    public function test_deleted_product_cannot_be_shown_normally(): void
    {
        $this->authenticatedUser();

        $product = Product::factory()->create(['barcode' => 'HIDE-1']);
        $product->delete();

        $response = $this->getJson('/api/product/' . $product->id);

        $response->assertNotFound();
        $response->assertJson(['success' => false, 'message' => 'Product not found.']);
    }

    public function test_deleted_product_cannot_be_updated(): void
    {
        $this->authenticatedUser();

        $product = Product::factory()->create(['barcode' => 'NO-UPDATE-1']);
        $product->delete();

        $response = $this->putJson('/api/product/' . $product->id, [
            'name' => 'Should Not Update',
            'barcode' => 'NO-UPDATE-2',
        ]);

        $response->assertNotFound();
    }
}
