<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrescriptionDispensingInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;
    private Product $product;
    private int $patientId;
    private int $prescriptionId;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->pharmacist = User::factory()->create([
            'role' => Role::query()->where('name', 'Pharmacist')->value('id'),
            'force_change_password' => false,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'Controlled Medicine', 'barcode' => 'RX-100',
            'is_controlled' => true, 'control_schedule' => 'C2',
        ]);
        $this->patientId = DB::table('patients')->insertGetId([
            'branch_id' => DB::table('branches')->value('id'), 'full_name' => 'Patient Identity',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->prescriptionId = DB::table('prescriptions')->insertGetId([
            'branch_id' => DB::table('branches')->value('id'), 'patient_id' => $this->patientId,
            'prescriber_name' => 'Dr Test', 'prescriber_license' => 'LIC-RX',
            'prescribed_at' => now()->toDateString(), 'expires_at' => now()->addMonth()->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->itemId = DB::table('prescription_items')->insertGetId([
            'prescription_id' => $this->prescriptionId, 'product_id' => $this->product->id,
            'allowed_quantity' => 7, 'dispensed_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->pharmacist, 'sanctum');
    }

    public function test_dispense_allocates_fefo_across_batches_and_persists_history(): void
    {
        $later = $this->batch('LATER', now()->addMonths(8)->toDateString(), 8);
        $earlier = $this->batch('EARLIER', now()->addMonths(2)->toDateString(), 3);

        $response = $this->postJson('/api/prescriptions/'.$this->prescriptionId.'/dispense', [
            'product_id' => $this->product->id, 'quantity' => 5,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Prescription dispensed.')
            ->assertJsonPath('data.remaining_quantity', 2)
            ->assertJsonPath('data.allocations.0.batch_id', $earlier->id)
            ->assertJsonPath('data.allocations.0.quantity', 3)
            ->assertJsonPath('data.allocations.1.batch_id', $later->id)
            ->assertJsonPath('data.allocations.1.quantity', 2);

        $this->assertSame(0, $earlier->fresh()->quantity);
        $this->assertSame(6, $later->fresh()->quantity);
        $this->assertDatabaseHas('prescription_items', ['id' => $this->itemId, 'dispensed_quantity' => 5]);
        $this->assertDatabaseCount('prescription_dispense_allocations', 2);
        $this->assertDatabaseHas('prescription_dispense_allocations', [
            'prescription_id' => $this->prescriptionId, 'prescription_item_id' => $this->itemId,
            'batch_id' => $earlier->id, 'quantity' => 3, 'user_id' => $this->pharmacist->id,
        ]);
        $this->assertDatabaseCount('stock', 2);
        $this->assertDatabaseHas('stock', [
            'batch_id' => $earlier->id, 'quantity' => 3, 'type' => 'PRESCRIPTION_DISPENSE_OUT',
            'reference_type' => 'prescription_dispense', 'reference_id' => $this->prescriptionId,
        ]);
        $this->assertCount(2, $response->json('data.allocations'));
    }

    public function test_full_dispense_marks_prescription_fulfilled(): void
    {
        $this->batch('FULL', now()->addMonths(2)->toDateString(), 7);

        $this->postJson('/api/prescriptions/'.$this->prescriptionId.'/dispense', [
            'product_id' => $this->product->id, 'quantity' => 7,
        ])->assertOk()->assertJsonPath('data.status', 'fulfilled');

        $this->assertDatabaseHas('prescriptions', ['id' => $this->prescriptionId, 'status' => 'fulfilled']);
        $this->assertDatabaseHas('prescription_items', ['id' => $this->itemId, 'dispensed_quantity' => 7]);
    }

    public function test_insufficient_stock_returns_409_and_rolls_back_everything(): void
    {
        $first = $this->batch('ONLY-ONE', now()->addMonth()->toDateString(), 2);
        $second = $this->batch('ONLY-TWO', now()->addMonths(2)->toDateString(), 2);

        $this->postJson('/api/prescriptions/'.$this->prescriptionId.'/dispense', [
            'product_id' => $this->product->id, 'quantity' => 5,
        ])->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'Insufficient sellable stock for prescription dispensing.',
                'errors' => [],
            ]);

        $this->assertSame(2, $first->fresh()->quantity);
        $this->assertSame(2, $second->fresh()->quantity);
        $this->assertDatabaseHas('prescription_items', ['id' => $this->itemId, 'dispensed_quantity' => 0]);
        $this->assertDatabaseCount('prescription_dispense_allocations', 0);
        $this->assertDatabaseCount('stock', 0);
    }

    public function test_expired_and_deleted_batches_are_unavailable(): void
    {
        $expired = $this->batch('EXPIRED', now()->subDay()->toDateString(), 10);
        $deleted = $this->batch('DELETED', now()->addYear()->toDateString(), 10);
        $deleted->delete();

        $this->postJson('/api/prescriptions/'.$this->prescriptionId.'/dispense', [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertStatus(409);

        $this->assertSame(10, $expired->fresh()->quantity);
        $this->assertSame(10, ProductBatch::withTrashed()->findOrFail($deleted->id)->quantity);
        $this->assertDatabaseCount('stock', 0);
    }

    public function test_remaining_quantity_and_expired_prescription_are_business_conflicts(): void
    {
        $this->batch('ENOUGH', now()->addYear()->toDateString(), 20);
        DB::table('prescription_items')->where('id', $this->itemId)->update(['dispensed_quantity' => 6]);

        $this->postJson('/api/prescriptions/'.$this->prescriptionId.'/dispense', [
            'product_id' => $this->product->id, 'quantity' => 2,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Dispensing quantity exceeds remaining prescription quantity.');

        DB::table('prescriptions')->where('id', $this->prescriptionId)->update(['expires_at' => now()->subDay()->toDateString()]);
        $this->postJson('/api/prescriptions/'.$this->prescriptionId.'/dispense', [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertStatus(409)->assertJsonPath('message', 'Prescription is expired.');

        $this->assertDatabaseCount('stock', 0);
        $this->assertDatabaseCount('prescription_dispense_allocations', 0);
    }

    public function test_detail_and_list_include_patient_and_product_identity(): void
    {
        $this->getJson('/api/prescriptions/'.$this->prescriptionId)
            ->assertOk()
            ->assertJsonPath('data.patient.full_name', 'Patient Identity')
            ->assertJsonPath('data.items.0.product.name', 'Controlled Medicine')
            ->assertJsonPath('data.items.0.product.barcode', 'RX-100')
            ->assertJsonPath('data.items.0.remaining_quantity', 7);

        $this->getJson('/api/prescriptions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.patient.id', $this->patientId)
            ->assertJsonPath('data.0.items.0.product.id', $this->product->id);
    }

    public function test_pharmacist_has_permission_safe_patient_and_product_read_access(): void
    {
        $this->getJson('/api/patients')->assertOk();
        $this->getJson('/api/patients/'.$this->patientId)->assertOk();
        $this->getJson('/api/product')->assertOk();

        $cashier = User::factory()->create([
            'role' => Role::query()->where('name', 'Cashier')->value('id'),
            'force_change_password' => false,
        ]);
        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/prescriptions/'.$this->prescriptionId.'/dispense', [
                'product_id' => $this->product->id, 'quantity' => 1,
            ])->assertForbidden();
    }

    private function batch(string $number, string $expiry, int $quantity): ProductBatch
    {
        return ProductBatch::query()->create([
            'product_id' => $this->product->id, 'batch_number' => $number,
            'expiry_date' => $expiry, 'quantity' => $quantity,
            'purchase_price' => 10, 'sale_price' => 15,
        ]);
    }
}
