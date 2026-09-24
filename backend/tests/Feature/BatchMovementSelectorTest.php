<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BatchMovementSelectorTest extends TestCase
{
    use RefreshDatabase;

    private int $main;
    private int $otherBranch;
    private Product $panadol;
    private Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->main = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->otherBranch = DB::table('branches')->insertGetId([
            'name' => 'Other Branch', 'code' => 'OTH', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->panadol = Product::factory()->create(['name' => 'Panadol 500mg', 'barcode' => '625100000001']);
        $this->otherProduct = Product::factory()->create(['name' => 'Other Product']);
        $this->actingAs(User::factory()->create(['role' => 1, 'branch_id' => $this->main, 'force_change_password' => false]), 'sanctum');
    }

    public function test_empty_query_returns_bounded_product_scoped_initial_options(): void
    {
        foreach (range(1, 30) as $index) {
            $this->batch($this->panadol->id, $this->main, sprintf('PAN-%02d', $index));
        }
        $this->batch($this->otherProduct->id, $this->main, 'PAN-OTHER-PRODUCT');

        $response = $this->getJson("/api/batch?product_id={$this->panadol->id}&page=1&per_page=25")
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.per_page', 25);

        $this->assertNotContains('PAN-OTHER-PRODUCT', collect($response->json('data'))->pluck('batch_number'));
    }

    public function test_partial_search_and_product_scope_return_only_matching_batches(): void
    {
        $this->batch($this->panadol->id, $this->main, 'PAN-A');
        $this->batch($this->panadol->id, $this->main, 'PAN-B');
        $this->batch($this->panadol->id, $this->main, 'OTHER-A');
        $this->batch($this->otherProduct->id, $this->main, 'PAN-FOREIGN');

        $response = $this->getJson("/api/batch?product_id={$this->panadol->id}&search=PAN&per_page=25")
            ->assertOk();

        $this->assertEqualsCanonicalizing(['PAN-A', 'PAN-B'], collect($response->json('data'))->pluck('batch_number')->all());
    }

    public function test_specific_branch_uses_existing_scope_and_all_branch_omits_that_restriction(): void
    {
        $this->batch($this->panadol->id, $this->main, 'PAN-MAIN');
        $this->batch($this->panadol->id, null, 'PAN-LEGACY-MAIN');
        $this->batch($this->panadol->id, $this->otherBranch, 'PAN-OTHER');

        $main = $this->getJson("/api/batch?product_id={$this->panadol->id}&branch_id={$this->main}&per_page=25")
            ->assertOk();
        $this->assertEqualsCanonicalizing(['PAN-MAIN', 'PAN-LEGACY-MAIN'], collect($main->json('data'))->pluck('batch_number')->all());

        $all = $this->getJson("/api/batch?product_id={$this->panadol->id}&per_page=25")
            ->assertOk();
        $this->assertEqualsCanonicalizing(['PAN-MAIN', 'PAN-LEGACY-MAIN', 'PAN-OTHER'], collect($all->json('data'))->pluck('batch_number')->all());
    }

    public function test_frontend_reloads_clears_and_hydrates_the_dependent_batch(): void
    {
        $page = file_get_contents(base_path('../frontend/src/app/pages/UniversalReportsPage.tsx'));
        $selector = file_get_contents(base_path('../frontend/src/app/components/UxPrimitives.tsx'));

        $this->assertStringContainsString('reloadKey={`${filterValues.product_id', $page);
        $this->assertStringContainsString('? { batch_id: "" }', $page);
        $this->assertStringContainsString('batchesApi', $page);
        $this->assertStringContainsString('selectedOption={selectedBatch}', $page);
        $this->assertStringContainsString('[search, reloadKey]', $selector);
        $this->assertStringContainsString('!resolved || loading', $selector);
    }

    private function batch(int $product, ?int $branch, string $number): int
    {
        return DB::table('product_batches')->insertGetId([
            'product_id' => $product, 'branch_id' => $branch, 'batch_number' => $number,
            'expiry_date' => '2028-02-22', 'purchase_price' => 2, 'sale_price' => 3,
            'quantity' => 5, 'inventory_state' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
