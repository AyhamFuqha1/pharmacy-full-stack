<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseEightBusinessCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->admin = User::factory()->create([
            'role' => Role::query()->where('name', 'Administrator')->value('id'),
        ]);
    }

    public function test_multi_branch_endpoints_allow_creating_and_listing_branch(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/branches', [
            'name' => 'North Branch',
            'code' => 'NORTH',
            'address' => 'North Street',
            'phone' => '0999999999',
            'is_active' => true,
        ]);

        $response->assertStatus(201)->assertJsonPath('code', 'NORTH');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/branches')
            ->assertOk()
            ->assertJsonFragment(['code' => 'NORTH']);
    }

    public function test_barcode_lookup_returns_only_sellable_batches(): void
    {
        $product = Product::factory()->create(['barcode' => 'BC-100']);

        DB::table('product_batches')->insert([
            [
                'product_id' => $product->id,
                'batch_number' => 'GOOD-BATCH',
                'expiry_date' => now()->addMonths(6)->toDateString(),
                'purchase_price' => 10,
                'sale_price' => 15,
                'quantity' => 12,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $product->id,
                'batch_number' => 'EXPIRED-BATCH',
                'expiry_date' => now()->subDay()->toDateString(),
                'purchase_price' => 10,
                'sale_price' => 15,
                'quantity' => 12,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/product/barcode/BC-100');

        $response->assertOk();
        $response->assertJsonPath('product.barcode', 'BC-100');
        $response->assertJsonCount(1, 'available_batches');
        $response->assertJsonPath('available_batches.0.batch_number', 'GOOD-BATCH');
    }

    public function test_controlled_medicine_prescription_dispensing_enforces_limits(): void
    {
        $product = Product::factory()->create([
            'barcode' => 'CTRL-001',
            'is_controlled' => true,
            'control_schedule' => 'C2',
        ]);
        $branchId = DB::table('branches')->value('id');

        $patientResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/patients', [
            'branch_id' => $branchId,
            'full_name' => 'Patient One',
            'phone_number' => '0911111111',
        ]);

        $patientId = $patientResponse->json('id');

        $prescriptionResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/prescriptions', [
            'branch_id' => $branchId,
            'patient_id' => $patientId,
            'prescriber_name' => 'Dr. House',
            'prescriber_license' => 'LIC-100',
            'prescribed_at' => now()->toDateString(),
            'expires_at' => now()->addDays(10)->toDateString(),
        ]);

        $prescriptionId = $prescriptionResponse->json('id');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/prescriptions/{$prescriptionId}/items", [
            'product_id' => $product->id,
            'allowed_quantity' => 5,
        ])->assertStatus(201);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/prescriptions/{$prescriptionId}/dispense", [
            'product_id' => $product->id,
            'quantity' => 3,
        ])->assertOk()->assertJsonPath('remaining_quantity', 2);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/prescriptions/{$prescriptionId}/dispense", [
            'product_id' => $product->id,
            'quantity' => 3,
        ])->assertStatus(422);
    }

    public function test_advanced_audit_report_endpoint_returns_summary_data(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        Product::factory()->create(['barcode' => 'AUDIT-001']);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/audit/reports/advanced');

        $response->assertOk();
        $response->assertJsonStructure([
            'filters',
            'audit_logs',
            'summary' => ['by_action', 'by_table'],
            'controlled_medicine_dispensing',
        ]);
    }

    public function test_deployment_readiness_endpoint_returns_checklist(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/deployment/readiness');

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'summary' => ['total_checks', 'passed', 'warnings', 'failures'],
            'checks',
            'generated_at',
        ]);
    }
}
