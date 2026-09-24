<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseUnitConversionTest extends TestCase
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

    public function test_base_box_and_carton_purchases_snapshot_and_post_authoritative_values(): void
    {
        [$product, $tablet, $box, $carton] = $this->productWithUnits();
        $provider = $this->provider();

        $cases = [
            [$tablet, 'Tablet', 1, 100, 2, 100, 2, 200],
            [$box, 'Box', 20, 5, 40, 100, 2, 200],
            [$carton, 'Carton', 100, 2, 180, 200, 1.8, 360],
        ];

        foreach ($cases as $index => [$unit, $name, $factor, $quantity, $cost, $baseQuantity, $baseCost, $value]) {
            $invoice = $this->postJson('/api/purchase-invoices', $this->payload(
                $provider,
                $product->id,
                $unit,
                "UNIT-$index",
                $quantity,
                $cost,
            ))->assertCreated()->json('data.id');

            $line = DB::table('prod_purch')->where('purchase_invoice_id', $invoice)->first();
            $this->assertEquals($quantity, $line->transaction_quantity);
            $this->assertEquals($factor, $line->conversion_factor);
            $this->assertEquals($baseQuantity, $line->base_quantity);
            $this->assertEquals($cost, $line->transaction_unit_cost);
            $this->assertEquals($baseCost, $line->purchase_price);
            $this->assertSame($name, $line->transaction_unit_name);
            $this->assertEquals($value, DB::table('purchase_invoices')->where('id', $invoice)->value('amount'));

            $this->postJson("/api/purchase-invoices/$invoice/post")->assertOk();
            $batch = DB::table('product_batches')->where('batch_number', "UNIT-$index")->first();
            $this->assertEquals($baseQuantity, $batch->quantity);
            $this->assertEquals($baseCost, $batch->purchase_price);

            $payable = DB::table('journal_entry_lines as lines')
                ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
                ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
                ->join('accounts', 'accounts.id', '=', 'lines.account_id')
                ->where(['vouchers.source_type' => 'purchase_invoice', 'vouchers.source_id' => $invoice, 'accounts.code' => '2100'])
                ->value('lines.amount');
            $this->assertEquals($value, $payable);
        }

        $this->assertEquals(760, app(AccountingReportService::class)->inventoryValuation()['value']);
    }

    public function test_unit_from_another_product_is_rejected(): void
    {
        [$product] = $this->productWithUnits();
        [, , , $otherCarton] = $this->productWithUnits();

        $this->postJson('/api/purchase-invoices', $this->payload(
            $this->provider(), $product->id, $otherCarton, 'WRONG-UNIT', 1, 10,
        ))->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_id']);
    }

    public function test_client_cannot_supply_conversion_or_base_quantity(): void
    {
        [$product, , $box] = $this->productWithUnits();
        $payload = $this->payload($this->provider(), $product->id, $box, 'TAMPER', 5, 40);
        $payload['items'][0]['conversion_factor'] = 1;
        $payload['items'][0]['base_quantity'] = 5;

        $this->postJson('/api/purchase-invoices', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.conversion_factor', 'items.0.base_quantity']);
    }

    public function test_draft_edit_recomputes_the_snapshot_from_the_selected_unit(): void
    {
        [$product, , $box, $carton] = $this->productWithUnits();
        $provider = $this->provider();
        $invoice = $this->postJson('/api/purchase-invoices', $this->payload(
            $provider, $product->id, $box, 'EDIT-UNIT', 5, 40,
        ))->assertCreated()->json('data');

        $payload = $this->payload($provider, $product->id, $carton, 'EDIT-UNIT', 2, 180);
        $payload['items'][0]['id'] = $invoice['items'][0]['id'];
        $this->putJson("/api/purchase-invoices/{$invoice['id']}", $payload)->assertOk();

        $line = DB::table('prod_purch')->where('id', $invoice['items'][0]['id'])->first();
        $this->assertEquals(2, $line->transaction_quantity);
        $this->assertEquals(100, $line->conversion_factor);
        $this->assertEquals(200, $line->base_quantity);
        $this->assertEquals(180, $line->transaction_unit_cost);
        $this->assertEquals(1.8, $line->purchase_price);
        $this->assertSame('Carton', $line->transaction_unit_name);
        $this->assertEquals(360, DB::table('purchase_invoices')->where('id', $invoice['id'])->value('amount'));
    }

    public function test_legacy_purchase_without_unit_metadata_remains_base_unit_compatible(): void
    {
        [$product] = $this->productWithUnits();
        $payload = $this->payload($this->provider(), $product->id, null, 'LEGACY', 10, 3);
        $invoice = $this->postJson('/api/purchase-invoices', $payload)->assertCreated()->json('data.id');
        $line = DB::table('prod_purch')->where('purchase_invoice_id', $invoice)->first();

        $this->assertNull($line->transaction_unit_id);
        $this->assertEquals(1, $line->conversion_factor);
        $this->assertEquals(10, $line->base_quantity);
        $this->assertEquals(3, $line->purchase_price);
        $this->postJson("/api/purchase-invoices/$invoice/post")->assertOk();
        $this->assertDatabaseHas('product_batches', ['batch_number' => 'LEGACY', 'quantity' => 10, 'purchase_price' => 3]);
    }

    private function productWithUnits(): array
    {
        $product = Product::factory()->create();
        $tablet = (int) DB::table('units')->where('name', 'tablet')->value('id');
        $box = (int) DB::table('units')->where('name', 'box')->value('id');
        $carton = (int) DB::table('units')->insertGetId([
            'name' => 'carton-'.$product->id,
            'symbol' => 'ctn-'.$product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->where('id', $product->id)->update(['base_unit_id' => $tablet]);
        foreach ([[$tablet, 'Tablet', 1, true], [$box, 'Box', 20, false], [$carton, 'Carton', 100, false]] as [$unit, $name, $factor, $base]) {
            DB::table('product_units')->insert([
                'product_id' => $product->id,
                'unit_id' => $unit,
                'display_name' => $name,
                'base_quantity' => $factor,
                'sale_price' => $base ? 5 : null,
                'is_base' => $base,
                'is_purchase_unit' => true,
                'is_sale_unit' => $base,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return [$product, $tablet, $box, $carton];
    }

    private function payload(int $provider, int $product, ?int $unit, string $batch, int $quantity, float $cost): array
    {
        return [
            'provider_id' => $provider,
            'date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product,
                'unit_id' => $unit,
                'batch_number' => $batch,
                'expire_date' => now()->addYear()->toDateString(),
                'amount' => $quantity,
                'purchase_price' => $cost,
                'item_discount' => 0,
            ]],
        ];
    }

    private function provider(): int
    {
        return DB::table('providers')->insertGetId([
            'name' => fake()->company(),
            'phone_num' => fake()->unique()->numerify('######'),
            'city' => 'Test',
        ]);
    }
}
