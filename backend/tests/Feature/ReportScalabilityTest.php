<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportScalabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->branch = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->admin = User::factory()->create(['name' => 'Report User', 'role' => 1, 'branch_id' => $this->branch, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_general_ledger_is_descending_paginated_and_totals_cover_full_filtered_result(): void
    {
        $account = (int) DB::table('accounts')->where('code', '5200')->value('id');
        foreach ([['2026-09-10', 10], ['2026-09-11', 20], ['2026-09-12', 30]] as [$date, $amount]) {
            $this->postJson('/api/expenses', ['expense_date' => $date, 'branch_id' => $this->branch, 'expense_account_id' => $account, 'amount' => $amount, 'payment_type' => 'cash', 'description' => "Expense $date"])->assertCreated();
        }

        $page1 = $this->getJson('/api/accounting/reports/general-ledger?account_code=5200&from=2026-09-10&to=2026-09-12&per_page=2&page=1')->assertOk();
        $page2 = $this->getJson('/api/accounting/reports/general-ledger?account_code=5200&from=2026-09-10&to=2026-09-12&per_page=2&page=2')->assertOk();

        $page1->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 3)->assertJsonPath('totals.total_debit', 60)->assertJsonPath('totals.total_credit', 0);
        $page2->assertJsonPath('meta.current_page', 2)->assertJsonPath('data.0.entry_date', '2026-09-10 00:00:00');
        $this->assertSame(['2026-09-12 00:00:00', '2026-09-11 00:00:00'], collect($page1->json('data'))->pluck('entry_date')->all());
        $ids = collect($page1->json('data'))->pluck('id')->concat(collect($page2->json('data'))->pluck('id'));
        $this->assertCount(3, $ids);
        $this->assertCount(3, $ids->unique());

        $filtered = $this->getJson('/api/accounting/reports/general-ledger?account_code=5200&from=2026-09-11&per_page=1&page=1')->assertOk();
        $filtered->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('totals.total_debit', 50);
    }

    public function test_stock_movements_default_to_newest_first_across_pages_and_keep_projection_and_filters(): void
    {
        $product = Product::factory()->create(['name' => 'Ordered Product', 'barcode' => 'ORDER-1']);
        $batch = DB::table('product_batches')->insertGetId(['product_id' => $product->id, 'branch_id' => $this->branch, 'batch_number' => 'ORDER-BATCH', 'expiry_date' => '2028-01-01', 'purchase_price' => 1, 'sale_price' => 2, 'quantity' => 4, 'inventory_state' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach (['2026-09-10 08:00:00', '2026-09-11 08:00:00', '2026-09-12 08:00:00', '2026-09-12 08:00:00'] as $timestamp) {
            $ids[] = DB::table('stock')->insertGetId(['batch_id' => $batch, 'branch_id' => $this->branch, 'quantity' => 1, 'type' => 'ADJUSTMENT_IN', 'reference_type' => 'stock_adjustment', 'reference_id' => null, 'user_id' => $this->admin->id, 'unit_cost' => 1, 'created_at' => $timestamp]);
        }
        $payload = ['report_key' => 'stock_movements', 'filters' => ['product_id' => $product->id, 'type' => 'ADJUSTMENT_IN'], 'per_page' => 2];
        $page1 = $this->postJson('/api/reports/query?page=1', $payload)->assertOk();
        $page2 = $this->postJson('/api/reports/query?page=2', $payload)->assertOk();

        $this->assertSame([$ids[3], $ids[2]], collect($page1->json('data.rows'))->pluck('movement_id')->all());
        $this->assertSame([$ids[1], $ids[0]], collect($page2->json('data.rows'))->pluck('movement_id')->all());
        $page1->assertJsonPath('data.meta.total', 4)->assertJsonPath('data.rows.0.product_name', 'Ordered Product')->assertJsonPath('data.rows.0.batch_number', 'ORDER-BATCH')->assertJsonPath('data.rows.0.user_name', 'Report User');
        $this->assertEmpty(collect($page1->json('data.rows'))->pluck('movement_id')->intersect(collect($page2->json('data.rows'))->pluck('movement_id')));
    }
}
