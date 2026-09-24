<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FrontendApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_current_user_contract_contains_role_and_resolved_permissions_without_sensitive_fields(): void
    {
        $user = $this->administrator();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role.name', 'Administrator')
            ->assertJsonPath('data.requires_password_change', false)
            ->assertJsonFragment(['products.manage'])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_customer_list_uses_paginated_envelope_and_search(): void
    {
        $this->actingAs($this->administrator(), 'sanctum');
        DB::table('customers')->insert([
            ['name' => 'Alpha Customer', 'phone_num' => '111', 'city' => 'A'],
            ['name' => 'Beta Customer', 'phone_num' => '222', 'city' => 'B'],
        ]);

        $this->getJson('/api/customers?search=Alpha&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Alpha Customer');
    }

    public function test_provider_list_uses_paginated_envelope_and_search(): void
    {
        $this->actingAs($this->administrator(), 'sanctum');
        DB::table('providers')->insert([
            ['name' => 'Alpha Provider', 'phone_num' => '111', 'city' => 'A'],
            ['name' => 'Beta Provider', 'phone_num' => '222', 'city' => 'B'],
        ]);

        $this->getJson('/api/providers?search=Beta&per_page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Beta Provider');
    }

    public function test_batch_detail_and_update_use_standard_envelopes_and_return_persisted_data(): void
    {
        $this->actingAs($this->administrator(), 'sanctum');
        $product = Product::factory()->create();
        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'CONTRACT-LOT',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity' => 10,
            'purchase_price' => 5,
            'sale_price' => 8,
        ]);

        $this->getJson("/api/batch/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $batch->id);

        $newExpiry = now()->addYears(2)->toDateString();
        $this->putJson("/api/batch/{$batch->id}", ['expiry_date' => $newExpiry])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.expiry_date', $newExpiry);
        $this->assertDatabaseHas('product_batches', ['id' => $batch->id, 'expiry_date' => $newExpiry]);
    }

    public function test_purchase_list_detail_and_validated_update_use_standard_envelopes(): void
    {
        $user = $this->administrator();
        $this->actingAs($user, 'sanctum');
        $providerId = $this->providerId();
        $product = Product::factory()->create();
        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $providerId,
            'user_id' => $user->id,
            'amount' => 10,
            'descount' => 0,
            'date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $lineId = DB::table('prod_purch')->insertGetId([
            'purchase_invoice_id' => $invoiceId,
            'product_id' => $product->id,
            'batch_number' => 'PURCHASE-LOT',
            'expire_date' => now()->addYear()->toDateString(),
            'amount' => 1,
            'purchase_price' => 10,
            'selling_price' => 15,
        ]);

        $this->getJson('/api/purchase-invoices?per_page=1')
            ->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonPath('data.0.id', $invoiceId);
        $this->getJson("/api/purchase-invoices/{$invoiceId}")
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.items.0.id', $lineId);

        $newExpiry = now()->addYears(2)->toDateString();
        $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'provider_id' => $providerId,
            'date' => now()->toDateString(),
            'invoice_discount' => 1,
            'items' => [[
                'id' => $lineId,
                'product_id' => $product->id,
                'batch_number' => 'PURCHASE-LOT-UPDATED',
                'expire_date' => $newExpiry,
                'amount' => 2,
                'purchase_price' => 10,
                'selling_price' => 15,
                'item_discount' => 0,
            ]],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.batch_number', 'PURCHASE-LOT-UPDATED')
            ->assertJsonPath('data.items.0.expire_date', $newExpiry);
    }

    public function test_sales_list_and_detail_use_standard_envelopes(): void
    {
        $user = $this->administrator();
        $this->actingAs($user, 'sanctum');
        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'customer_id' => $this->customerId(),
            'user_id' => $user->id,
            'total_price' => 25,
            'discount' => 0,
            'profit' => 5,
            'date' => now()->toDateString(),
        ]);

        $this->getJson('/api/sales-invoices?per_page=1')
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('meta.per_page', 1);
        $this->getJson("/api/sales-invoices/{$invoiceId}")
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.id', $invoiceId);
    }

    public function test_payment_and_receipt_voucher_lists_use_paginated_envelopes(): void
    {
        $user = $this->administrator();
        $this->actingAs($user, 'sanctum');
        DB::table('payment_vouchers')->insert([
            'provider_id' => $this->providerId(), 'user_id' => $user->id,
            'amount' => 10, 'date' => now()->toDateString(),
        ]);
        DB::table('receipt_vouchers')->insert([
            'customer_id' => $this->customerId(), 'user_id' => $user->id,
            'amount' => 10, 'date' => now()->toDateString(),
        ]);

        $this->getJson('/api/payment-vouchers?per_page=1')
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/receipt-vouchers?per_page=1')
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('meta.total', 1);
    }

    public function test_validation_errors_use_standard_error_envelope(): void
    {
        $this->actingAs($this->administrator(), 'sanctum');

        $this->postJson('/api/customers', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['name', 'phone_num']]);
    }

    public function test_permission_denial_uses_standard_error_envelope(): void
    {
        $role = Role::query()->where('name', 'Pharmacist')->firstOrFail();
        $user = User::factory()->create(['role' => $role->id, 'force_change_password' => false]);

        $this->actingAs($user, 'sanctum')->getJson('/api/customers')
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Forbidden.',
                'errors' => [],
            ]);
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => 1, 'force_change_password' => false]);
    }

    private function customerId(): int
    {
        return DB::table('customers')->insertGetId([
            'name' => 'Contract Customer', 'phone_num' => '100', 'city' => 'Test',
        ]);
    }

    private function providerId(): int
    {
        return DB::table('providers')->insertGetId([
            'name' => 'Contract Provider', 'phone_num' => '200', 'city' => 'Test',
        ]);
    }
}
