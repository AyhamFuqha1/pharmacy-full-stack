<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerProductPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $customer;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false, 'branch_id' => DB::table('branches')->value('id')]);
        $this->actingAs($this->admin, 'sanctum');
        $this->customer = DB::table('customers')->insertGetId(['name' => 'History Customer', 'phone_num' => '0599000001', 'city' => 'Test']);
        $this->unit = DB::table('units')->insertGetId(['name' => 'History Unit '.uniqid(), 'symbol' => 'HU'.random_int(1000, 9999), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_same_customer_product_and_unit_returns_only_five_newest_prices(): void
    {
        $product = Product::factory()->create();
        foreach ([4, 9, 6, 8, 5, 7] as $index => $price) $this->observation($this->customer, $product->id, $this->unit, $price, "2026-09-0".($index + 1));

        $data = $this->getJson($this->url($this->customer, $product->id, $this->unit))->assertOk()->json('data');
        $this->assertTrue($data['available']);
        $this->assertSame(7, $data['last_price']);
        $this->assertSame(5, $data['min_price']);
        $this->assertSame(9, $data['max_price']);
        $this->assertCount(5, $data['recent_prices']);
        $this->assertSame([7, 5, 8, 6, 9], array_column($data['recent_prices'], 'price'));
    }

    public function test_customer_product_and_unit_scopes_do_not_leak_or_mix(): void
    {
        $product = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        $otherCustomer = DB::table('customers')->insertGetId(['name' => 'Other', 'phone_num' => '0599000002', 'city' => 'Test']);
        $otherUnit = DB::table('units')->insertGetId(['name' => 'Other Unit '.uniqid(), 'symbol' => 'OU'.random_int(1000, 9999), 'created_at' => now(), 'updated_at' => now()]);
        $this->observation($this->customer, $product->id, $this->unit, 7, '2026-09-01');
        $this->observation($otherCustomer, $product->id, $this->unit, 99, '2026-09-02');
        $this->observation($this->customer, $otherProduct->id, $this->unit, 88, '2026-09-03');
        $this->observation($this->customer, $product->id, $otherUnit, 77, '2026-09-04');

        $data = $this->getJson($this->url($this->customer, $product->id, $this->unit))->assertOk()->json('data');
        $this->assertSame([7], array_column($data['recent_prices'], 'price'));
        $this->getJson($this->url($otherCustomer, $otherProduct->id, $this->unit))->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_cancelled_sales_are_excluded_and_returns_are_not_queried(): void
    {
        $product = Product::factory()->create();
        $this->observation($this->customer, $product->id, $this->unit, 6, '2026-09-01');
        $this->observation($this->customer, $product->id, $this->unit, 50, '2026-09-02', true);
        $data = $this->getJson($this->url($this->customer, $product->id, $this->unit))->assertOk()->json('data');
        $this->assertSame(6, $data['last_price']);
        $this->assertCount(1, $data['recent_prices']);
    }

    public function test_no_history_has_stable_empty_contract(): void
    {
        $product = Product::factory()->create();
        $this->getJson($this->url($this->customer, $product->id, $this->unit))->assertOk()->assertJson([
            'success' => true, 'data' => ['available' => false, 'last_price' => null, 'min_price' => null, 'max_price' => null, 'recent_prices' => []],
        ])->assertJsonCount(0, 'data.recent_prices');
    }

    public function test_split_batch_allocations_are_one_price_observation(): void
    {
        $product = Product::factory()->create();
        $invoice = $this->invoice($this->customer, '2026-09-05');
        $this->line($invoice, $this->batch($product->id, 'SPLIT-1'), $this->unit, 7);
        $this->line($invoice, $this->batch($product->id, 'SPLIT-2'), $this->unit, 7);
        $this->getJson($this->url($this->customer, $product->id, $this->unit))->assertOk()->assertJsonCount(1, 'data.recent_prices');
    }

    public function test_endpoint_requires_sales_permission_and_never_exposes_cost_fields(): void
    {
        $product = Product::factory()->create();
        $this->observation($this->customer, $product->id, $this->unit, 7, '2026-09-01');
        $response = $this->getJson($this->url($this->customer, $product->id, $this->unit))->assertOk();
        $response->assertJsonMissingPath('data.cost_price')->assertJsonMissingPath('data.recent_prices.0.cost_price');

        $role = DB::table('roles')->insertGetId(['name' => 'No sales '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['role' => $role, 'force_change_password' => false]);
        $this->actingAs($user, 'sanctum')->getJson($this->url($this->customer, $product->id, $this->unit))->assertForbidden();
    }

    public function test_frontend_uses_generation_cache_and_never_assigns_history_to_price(): void
    {
        $source = file_get_contents(base_path('../frontend/src/app/pages/ClosureSalesPage.tsx'));
        $this->assertStringContainsString('historyRequest.current === generation', $source);
        $this->assertStringContainsString('priceHistoryCache.current', $source);
        $this->assertStringNotContainsString('price: priceHistory', $source);
        $this->assertStringNotContainsString('price: history.', $source);
    }

    private function url(int $customer, int $product, int $unit): string
    {
        return "/api/customers/$customer/products/$product/price-history?unit_id=$unit";
    }

    private function observation(int $customer, int $product, int $unit, float $price, string $date, bool $cancelled = false): void
    {
        $invoice = $this->invoice($customer, $date, $cancelled);
        $this->line($invoice, $this->batch($product, 'H-'.$invoice), $unit, $price);
    }

    private function invoice(int $customer, string $date, bool $cancelled = false): int
    {
        return DB::table('sales_invoices')->insertGetId([
            'document_number' => 'SAL-H-'.uniqid(), 'branch_id' => DB::table('branches')->value('id'), 'customer_id' => $customer,
            'user_id' => $this->admin->id, 'total_price' => 10, 'discount' => 0, 'paid_amount' => 10, 'payment_method' => 'cash',
            'profit' => 1, 'date' => $date, 'due_date' => $date, 'deleted_at' => $cancelled ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function batch(int $product, string $number): int
    {
        return DB::table('product_batches')->insertGetId([
            'product_id' => $product, 'branch_id' => DB::table('branches')->value('id'), 'batch_number' => $number,
            'expiry_date' => '2027-01-01', 'purchase_price' => 2, 'sale_price' => 5, 'quantity' => 10,
            'inventory_state' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function line(int $invoice, int $batch, int $unit, float $price): void
    {
        DB::table('prod_sales')->insert([
            'sales_invoice_id' => $invoice, 'batch_id' => $batch, 'amount' => 1, 'price' => $price,
            'transaction_unit_id' => $unit, 'transaction_quantity' => 1, 'base_quantity' => 1,
            'original_unit_price' => $price, 'applied_unit_price' => $price, 'discount' => 0, 'cost_price' => 2,
            'line_total' => $price, 'invoice_discount_share' => 0, 'final_total' => $price, 'profit' => $price - 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
