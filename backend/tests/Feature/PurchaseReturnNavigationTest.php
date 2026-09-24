<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReturnNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_return_list_and_router_share_the_canonical_detail_path(): void
    {
        $routes = file_get_contents(base_path('../frontend/src/app/routes.ts'));
        $page = file_get_contents(base_path('../frontend/src/app/pages/ReturnHistoryPage.tsx'));

        $this->assertStringContainsString('path: "returns/purchases/:id"', $routes);
        $this->assertStringContainsString('kind === "sales" ? "/returns/sales" : "/returns/purchases"', $page);
        $this->assertStringContainsString('to={`${detailBasePath}/${row.id}`}', $page);
        $this->assertStringNotContainsString('to={`/returns/${kind}/${row.id}`}', $page);
    }

    public function test_valid_purchase_return_detail_is_readable_and_unknown_id_is_a_data_404(): void
    {
        $this->seed();
        $admin = User::factory()->create(['role' => 1, 'force_change_password' => false]);
        $provider = DB::table('providers')->insertGetId(['name' => 'Navigation Supplier', 'phone_num' => '0590000000', 'city' => 'Test']);
        $product = Product::factory()->create();
        $batch = DB::table('product_batches')->insertGetId([
            'product_id' => $product->id, 'batch_number' => 'NAV-BATCH', 'expiry_date' => now()->addYear(),
            'purchase_price' => 5, 'sale_price' => 8, 'quantity' => 10, 'inventory_state' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoice = DB::table('purchase_invoices')->insertGetId([
            'document_number' => 'PUR-NAV-1', 'provider_id' => $provider, 'user_id' => $admin->id,
            'amount' => 10, 'descount' => 0, 'invoice_discount' => 0, 'total_before_discount' => 10,
            'total_after_discount' => 10, 'date' => now()->toDateString(), 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $line = DB::table('prod_purch')->insertGetId([
            'purchase_invoice_id' => $invoice, 'product_id' => $product->id, 'batch_id' => $batch,
            'batch_number' => 'NAV-BATCH', 'amount' => 2, 'purchase_price' => 5, 'selling_price' => 8,
            'discount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $return = DB::table('purch_returns')->insertGetId([
            'document_number' => 'PRET-NAV-1', 'invoice_id' => $invoice, 'invoice_item_id' => $line,
            'batch_id' => $batch, 'quantity' => 1, 'return_price' => 4, 'cost_price' => 5,
            'line_total_return' => 4, 'reason' => 'Navigation test', 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')->getJson("/api/purchase-returns/$return")
            ->assertOk()
            ->assertJsonPath('data.id', $return)
            ->assertJsonPath('data.document_number', 'PRET-NAV-1')
            ->assertJsonPath('data.source_document_number', 'PUR-NAV-1')
            ->assertJsonPath('data.provider_name', 'Navigation Supplier')
            ->assertJsonPath('data.product_name', $product->name)
            ->assertJsonPath('data.batch_number', 'NAV-BATCH')
            ->assertJsonPath('data.original_inventory_value', 5)
            ->assertJsonPath('data.supplier_credit_value', 4)
            ->assertJsonPath('data.inventory_adjustment', 1);

        $this->getJson('/api/purchase-returns/999999')->assertNotFound();
    }
}
