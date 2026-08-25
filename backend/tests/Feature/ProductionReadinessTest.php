<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_product_list_is_paginated_and_searchable(): void
    {
        Product::factory()->create(['name' => 'Amoxicillin', 'barcode' => 'AMOX-1']);
        Product::factory()->create(['name' => 'Paracetamol', 'barcode' => 'PARA-1']);
        $user = User::factory()->create(['role' => Role::where('name', 'Pharmacist')->value('id')]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/product?search=Amox&per_page=1');

        $response->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Amoxicillin');
    }

    public function test_health_endpoint_confirms_database_connectivity(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'ok', 'database' => 'ok']);
    }

    public function test_notification_service_generates_low_stock_alerts(): void
    {
        $user = User::factory()->create(['role' => Role::where('name', 'Administrator')->value('id')]);
        $productId = DB::table('products')->insertGetId(['name' => 'Low Stock Item', 'barcode' => 'LOW-1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_batches')->insert(['product_id' => $productId, 'batch_number' => 'LOW', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 0, 'purchase_price' => 2, 'sale_price' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $alerts = app(NotificationService::class)->forUser($user->id);

        $this->assertTrue(collect($alerts)->contains(fn ($alert) => $alert->type === 'low_stock'));
    }
}
