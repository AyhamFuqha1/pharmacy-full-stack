<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GlobalEntitySearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_product_search_is_partial_multi_token_arabic_aware_and_barcode_ranked(): void
    {
        $red = Product::factory()->create(['name' => 'شبس أحمر كبير', 'barcode' => '625202600001']);
        Product::factory()->create(['name' => 'شبس حار', 'barcode' => '625202600002']);
        Product::factory()->create(['name' => 'عرض شبس عائلي', 'barcode' => '625202600003']);
        Product::factory()->create(['name' => 'عصير برتقال', 'barcode' => '625202600004']);

        $partial = $this->getJson('/api/product?search='.urlencode('شبس').'&per_page=25')->assertOk();
        $this->assertEqualsCanonicalizing(['شبس أحمر كبير', 'شبس حار', 'عرض شبس عائلي'], collect($partial->json('data'))->pluck('name')->all());
        $this->assertNotContains('عصير برتقال', collect($partial->json('data'))->pluck('name')->all());

        $tokens = $this->getJson('/api/product?search='.urlencode('  شبس   احمر  ').'&per_page=25')->assertOk();
        $tokens->assertJsonPath('data.0.id', $red->id)->assertJsonPath('data.0.name', 'شبس أحمر كبير');
        $this->assertCount(1, $tokens->json('data'));

        $barcode = $this->getJson('/api/product?search=625202600001&per_page=25')->assertOk();
        $barcode->assertJsonPath('data.0.id', $red->id)->assertJsonPath('data.0.barcode', '625202600001');
    }

    public function test_search_results_are_bounded_paginated_and_party_search_is_contains_based(): void
    {
        Product::factory()->count(31)->create(['name' => 'منتج بحث']);
        $first = $this->getJson('/api/product?search='.urlencode('منتج').'&per_page=25&page=1')->assertOk();
        $second = $this->getJson('/api/product?search='.urlencode('منتج').'&per_page=25&page=2')->assertOk();
        $first->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.total', 31);
        $this->assertCount(25, $first->json('data'));
        $this->assertCount(6, $second->json('data'));

        DB::table('customers')->insert(['name' => 'محمد أحمد', 'phone_num' => '0599000001', 'city' => 'Test']);
        DB::table('providers')->insert(['name' => 'مورد محمد للأدوية', 'phone_num' => '0599000002', 'city' => 'Test']);
        $this->getJson('/api/lookups/parties?q='.urlencode('محمد').'&party_type=customer')->assertOk()->assertJsonPath('data.0.display_name', 'محمد أحمد');
        $this->getJson('/api/lookups/parties?q=0002&party_type=supplier')->assertOk()->assertJsonPath('data.0.display_name', 'مورد محمد للأدوية');
    }

    public function test_batch_search_preserves_product_and_branch_scope(): void
    {
        $main = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $otherBranch = DB::table('branches')->insertGetId(['name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $wanted = $this->batch($productA->id, $main, 'LOT-A-SEARCH');
        $this->batch($productA->id, $otherBranch, 'LOT-A-OTHER');
        $this->batch($productB->id, $main, 'LOT-B-SEARCH');

        $response = $this->getJson("/api/batch?product_id={$productA->id}&branch_id=$main&search=LOT&per_page=25")->assertOk();
        $response->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $wanted)->assertJsonPath('data.0.batch_number', 'LOT-A-SEARCH');
    }

    public function test_shared_remote_selector_has_keyboard_rtl_debounce_and_stale_request_protection(): void
    {
        $component = file_get_contents(base_path('../frontend/src/app/components/UxPrimitives.tsx'));
        foreach (['ArrowDown', 'ArrowUp', 'Enter', 'Escape', 'AbortController', 'request.current', 'setTimeout', '300'] as $needle) $this->assertStringContainsString($needle, $component);
        $this->assertStringContainsString('document.documentElement.dir', $component);
        $this->assertStringContainsString('selectedOption', $component);
    }

    public function test_mounted_high_priority_workflows_reuse_remote_search_without_master_list_preloads(): void
    {
        $pages = [
            'ClosureSalesPage.tsx' => ['partyEntityLoader("customer")', 'productEntityLoader'],
            'PurchaseInvoicePage.tsx' => ['partyEntityLoader("supplier")', 'productEntityLoader', 'selectedOption'],
            'FriendlyInventoryPages.tsx' => ['batchEntityLoader', 'productEntityLoader'],
            'ControlledLedgerPage.tsx' => ['batchEntityLoader', 'productEntityLoader'],
            'PrescriptionDetailsPage.tsx' => ['controlledProductEntityLoader'],
            'CreatePrescriptionPage.tsx' => ['patientEntityLoader', 'selectedOption'],
            'UniversalReportsPage.tsx' => ['partyEntityLoader', 'productEntityLoader', 'batchEntityLoader'],
        ];

        foreach ($pages as $file => $needles) {
            $source = file_get_contents(base_path('../frontend/src/app/pages/'.$file));
            $this->assertStringContainsString('RemoteEntitySelect', $source, $file);
            foreach ($needles as $needle) $this->assertStringContainsString($needle, $source, $file);
        }

        $this->assertStringNotContainsString('loadCustomers(c.signal)', file_get_contents(base_path('../frontend/src/app/pages/ClosureSalesPage.tsx')));
        $this->assertStringNotContainsString('loadProducts(controller.signal)', file_get_contents(base_path('../frontend/src/app/pages/PurchaseInvoicePage.tsx')));
        $this->assertStringNotContainsString('.listAll(', file_get_contents(base_path('../frontend/src/app/pages/FriendlyInventoryPages.tsx')));
        $returns = file_get_contents(base_path('../frontend/src/app/pages/InvoiceReturnPage.tsx'));
        $this->assertStringContainsString('invoice_item_id', $returns);
        $this->assertStringNotContainsString('productEntityLoader', $returns);
    }

    public function test_controlled_product_lookup_remains_server_filtered(): void
    {
        Product::factory()->create(['name' => 'دواء عادي', 'barcode' => 'NORMAL-1', 'is_controlled' => false]);
        $controlled = Product::factory()->create(['name' => 'دواء مراقب', 'barcode' => 'CTRL-1', 'is_controlled' => true]);

        $response = $this->getJson('/api/product?search='.urlencode('دواء').'&is_controlled=1&per_page=25')->assertOk();
        $response->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $controlled->id);
    }

    private function batch(int $product, int $branch, string $number): int
    {
        return DB::table('product_batches')->insertGetId(['product_id' => $product, 'branch_id' => $branch, 'batch_number' => $number, 'expiry_date' => '2028-01-01', 'quantity' => 1, 'purchase_price' => 1, 'sale_price' => 2, 'inventory_state' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }
}
