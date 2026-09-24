<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ErpClosurePassTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false, 'branch_id' => $this->branch()]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_virtual_reports_are_discoverable_queryable_and_permission_consistent(): void
    {
        $keys = collect($this->getJson('/api/reports/catalog')->assertOk()->json('data'))->pluck('key');
        foreach (['product_statement', 'batch_statement', 'ar_aging', 'ap_aging', 'controlled_medicine_ledger'] as $key) {
            $this->assertTrue($keys->contains($key), $key);
        }foreach (['customer_statement', 'supplier_statement', 'recall_traceability'] as $key) {
            $this->assertFalse($keys->contains($key), $key);
        }
        $customer = $this->customer();
        $payload = ['report_key' => 'customer_statement', 'filters' => ['customer_id' => $customer], 'columns' => ['entry_date', 'source_type', 'debit', 'credit', 'running_balance']];
        $this->postJson('/api/reports/query', $payload)->assertOk()->assertJsonPath('data.report.key', 'customer_statement');
        $this->postJson('/api/reports/print-data', $payload)->assertOk()->assertJsonPath('data.print_too_large', false);
        $this->postJson('/api/reports/saved', ['name' => 'Customer statement', 'report_key' => 'customer_statement', 'filters' => ['customer_id' => $customer], 'visible_columns' => $payload['columns']])->assertUnprocessable();
        $saved = DB::table('saved_reports')->insertGetId(['owner_id' => $this->admin->id, 'name' => 'Historical customer statement', 'report_key' => 'customer_statement', 'filters' => json_encode(['customer_id' => $customer]), 'visible_columns' => json_encode($payload['columns']), 'sort' => 'entry_date', 'direction' => 'asc', 'is_shared' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/reports/saved/$saved/execute")->assertOk()->assertJsonPath('data.report.key', 'customer_statement');
    }

    public function test_customer_and_supplier_360_have_paginated_real_timelines(): void
    {
        $customer = $this->customer();
        $provider = $this->provider();
        DB::table('sales_invoices')->insert(['document_number' => 'SAL-TL', 'customer_id' => $customer, 'user_id' => $this->admin->id, 'branch_id' => $this->branch(), 'total_price' => 20, 'discount' => 0, 'paid_amount' => 0, 'payment_method' => 'cash', 'profit' => 5, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_invoices')->insert(['document_number' => 'PUR-TL', 'provider_id' => $provider, 'user_id' => $this->admin->id, 'branch_id' => $this->branch(), 'amount' => 30, 'descount' => 0, 'invoice_discount' => 0, 'total_before_discount' => 30, 'total_after_discount' => 30, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson("/api/customers/$customer/360")->assertOk()->assertJsonStructure(['data' => ['profile', 'current_balance', 'ar_aging', 'recent_activity', 'activity_endpoint']]);
        $this->getJson("/api/customers/$customer/360/activity?activity_type=sale")->assertOk()->assertJsonPath('data.rows.0.activity_type', 'sale')->assertJsonStructure(['data' => ['rows', 'meta']]);
        $this->getJson("/api/providers/$provider/360")->assertOk()->assertJsonPath('data.preferred_supplier_supported', false);
        $this->getJson("/api/providers/$provider/360/activity?activity_type=purchase")->assertOk()->assertJsonPath('data.rows.0.activity_type', 'purchase');
    }

    public function test_product_and_batch_360_honor_date_range_and_data_completeness(): void
    {
        $p = Product::factory()->create(['is_controlled' => true]);
        $batch = $this->batch($p->id, 'CLOSE-B');
        $invoice = DB::table('sales_invoices')->insertGetId(['document_number' => 'SAL-P360', 'customer_id' => $this->customer(), 'user_id' => $this->admin->id, 'branch_id' => $this->branch(), 'total_price' => 20, 'discount' => 0, 'paid_amount' => 20, 'payment_method' => 'cash', 'profit' => 8, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prod_sales')->insert(['sales_invoice_id' => $invoice, 'batch_id' => $batch, 'amount' => 2, 'price' => 10, 'cost_price' => 6, 'discount' => 0, 'line_total' => 20, 'final_total' => 20, 'profit' => 8, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock')->insert(['batch_id' => $batch, 'branch_id' => $this->branch(), 'quantity' => 2, 'type' => 'SALE_OUT', 'reference_type' => 'sale', 'reference_id' => $invoice, 'user_id' => $this->admin->id, 'created_at' => now()]);
        $this->getJson("/api/products/$p->id/360?from=".now()->subDay()->toDateString().'&to='.now()->toDateString())->assertOk()->assertJsonPath('data.sold_quantity', 2)->assertJsonPath('data.revenue', 20)->assertJsonPath('data.cogs', 12)->assertJsonPath('data.gross_profit', 8);
        $this->getJson("/api/batches/$batch/360")->assertOk()->assertJsonPath('data.batch_number', 'CLOSE-B')->assertJsonPath('data.linkage_complete', true)->assertJsonStructure(['data' => ['originating_purchases', 'sales', 'prescription_dispensing', 'recent_movement_timeline']]);
    }

    public function test_controlled_ledger_enforces_field_privacy_and_recall_reports_linkage_flags(): void
    {
        $p = Product::factory()->create(['is_controlled' => true]);
        $batch = $this->batch($p->id, 'CTRL-B');
        $patient = DB::table('patients')->insertGetId(['branch_id' => $this->branch(), 'full_name' => 'Private Patient', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $rx = DB::table('prescriptions')->insertGetId(['branch_id' => $this->branch(), 'patient_id' => $patient, 'prescriber_name' => 'Dr Safe', 'prescriber_license' => 'L1', 'prescribed_at' => now()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock')->insert(['batch_id' => $batch, 'branch_id' => $this->branch(), 'quantity' => 1, 'type' => 'PRESCRIPTION_DISPENSE_OUT', 'reference_type' => 'prescription_dispense', 'reference_id' => $rx, 'user_id' => $this->admin->id, 'created_at' => now()]);
        $this->getJson('/api/controlled-medicine-ledger')->assertOk()->assertJsonPath('data.patient_identity_included', true)->assertJsonPath('data.rows.0.patient.name', 'Private Patient');
        $viewer = $this->userWithPermission('controlled.view');
        $this->actingAs($viewer, 'sanctum')->getJson('/api/controlled-medicine-ledger')->assertOk()->assertJsonPath('data.patient_identity_included', false)->assertJsonMissingPath('data.rows.0.patient');
        $this->actingAs($this->admin, 'sanctum');
        $recall = DB::table('batch_recalls')->insertGetId(['product_id' => $p->id, 'batch_id' => $batch, 'reason' => 'Safety', 'issued_at' => now(), 'status' => 'active', 'actor_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock')->insert(['batch_id' => $batch, 'branch_id' => $this->branch(), 'quantity' => 1, 'type' => 'ADJUSTMENT_OUT', 'reference_type' => null, 'reference_id' => null, 'user_id' => $this->admin->id, 'created_at' => now()]);
        $this->getJson("/api/recalls/$recall/traceability")->assertOk()->assertJsonPath('data.linkage_complete', false)->assertJsonPath('data.linkage_gaps.count', 1);
    }

    public function test_alerts_import_retention_and_reorder_last_supplier_are_authoritative(): void
    {
        Storage::fake('local');
        $csv = "name,barcode\nImported Product,IMP-1\n";
        $job = $this->postJson('/api/imports/preview', ['domain' => 'products', 'file' => UploadedFile::fake()->createWithContent('products.csv', $csv)])->assertCreated()->assertJsonPath('data.job.status', 'previewed')->json('data.job');
        $this->assertNotNull($job['checksum']);
        $this->get("/api/imports/{$job['id']}/source")->assertOk();
        $this->postJson("/api/imports/{$job['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'completed');
        $p = Product::factory()->create(['reorder_level' => 10, 'reorder_quantity' => 20]);
        $provider = $this->provider();
        $purchase = DB::table('purchase_invoices')->insertGetId(['document_number' => 'PUR-REORDER', 'provider_id' => $provider, 'user_id' => $this->admin->id, 'branch_id' => $this->branch(), 'amount' => 50, 'descount' => 0, 'invoice_discount' => 0, 'total_before_discount' => 50, 'total_after_discount' => 50, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prod_purch')->insert(['purchase_invoice_id' => $purchase, 'product_id' => $p->id, 'batch_number' => 'R1', 'amount' => 5, 'expire_date' => now()->addYear(), 'purchase_price' => 10, 'selling_price' => 12, 'discount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/inventory/reorder?branch_id='.$this->branch())->assertOk()->assertJsonFragment(['preferred_supplier_supported' => false, 'last_purchase_cost' => 10]);
        $this->getJson('/api/alerts/actionable')->assertOk()->assertJsonFragment(['alert_type' => 'out_of_stock', 'entity_id' => $p->id]);
    }

    public function test_rich_print_contract_overflow_and_period_reopen(): void
    {
        $expense = $this->postJson('/api/expenses', ['amount' => 20, 'payment_type' => 'cash', 'expense_date' => now()->toDateString(), 'branch_id' => $this->branch(), 'description' => 'Print expense'])->assertCreated()->json('data.id');
        $this->getJson("/api/print-documents/expense/$expense")->assertOk()->assertJsonStructure(['data' => ['meta' => ['print_identity', 'title', 'generated_at', 'generated_by', 'currency', 'suggested_orientation'], 'content' => ['entity', 'rows_lines', 'totals_summary', 'source_references']]])->assertJsonMissingPath('data.meta.pharmacy');
        $rows = [];
        for ($i = 0; $i < 5001; $i++) {
            $rows[] = ['name' => 'Bulk '.$i, 'barcode' => 'BULK-'.$i, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()];
        }foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('products')->insert($chunk);
        }$this->postJson('/api/reports/print-data', ['report_key' => 'products'])->assertOk()->assertJsonPath('data.print_too_large', true)->assertJsonPath('data.total_rows', 5001)->assertJsonPath('data.max_rows', 5000)->assertJsonPath('data.export_csv_available', true);
        $period = DB::table('accounting_periods')->where('status', 'open')->first();
        $this->postJson("/api/accounting-periods/$period->id/close")->assertOk();
        $expensePayload = ['amount' => 5, 'payment_type' => 'cash', 'expense_date' => now()->toDateString(), 'branch_id' => $this->branch(), 'description' => 'Period check'];
        $this->postJson('/api/expenses', $expensePayload)->assertConflict();
        $this->postJson("/api/accounting-periods/$period->id/reopen", [])->assertUnprocessable();
        $unauthorized = User::factory()->create(['force_change_password' => false]);
        $this->actingAs($unauthorized, 'sanctum')->postJson("/api/accounting-periods/$period->id/reopen", ['reason' => 'Correction'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/accounting-periods/$period->id/reopen", ['reason' => 'Correction required'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('audit_logs', ['record_id' => $period->id, 'action_type' => 'accounting_period_reopen']);
        $this->postJson('/api/expenses', $expensePayload)->assertCreated();
    }

    public function test_cost_columns_cannot_leak_through_query_print_export_or_saved_reports(): void
    {
        $viewer = $this->userWithPermissions(['reports.view_operational', 'reports.export', 'reports.print']);
        $this->actingAs($viewer, 'sanctum');
        $payload = ['report_key' => 'batches', 'columns' => ['id', 'purchase_price']];
        $this->postJson('/api/reports/query', $payload)->assertUnprocessable();
        $this->postJson('/api/reports/print-data', $payload)->assertUnprocessable();
        $this->postJson('/api/reports/export/csv', $payload)->assertUnprocessable();
        $this->postJson('/api/reports/saved', ['name' => 'Unsafe saved report', 'report_key' => 'batches', 'visible_columns' => ['id', 'purchase_price']])->assertUnprocessable();
        $saved = DB::table('saved_reports')->insertGetId(['owner_id' => $viewer->id, 'name' => 'Historical unsafe report', 'report_key' => 'batches', 'filters' => json_encode([]), 'visible_columns' => json_encode(['id', 'purchase_price']), 'sort' => 'id', 'direction' => 'asc', 'is_shared' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/reports/saved/$saved/execute")->assertUnprocessable();
    }

    private function branch(): int
    {
        return (int) DB::table('branches')->where('code', 'MAIN')->value('id');
    }

    private function customer(): int
    {
        return DB::table('customers')->insertGetId(['name' => 'Closure Customer '.uniqid(), 'phone_num' => uniqid(), 'city' => 'Test']);
    }

    private function provider(): int
    {
        return DB::table('providers')->insertGetId(['name' => 'Closure Provider '.uniqid(), 'phone_num' => uniqid(), 'city' => 'Test']);
    }

    private function batch(int $product, string $number): int
    {
        return DB::table('product_batches')->insertGetId(['product_id' => $product, 'branch_id' => $this->branch(), 'batch_number' => $number, 'expiry_date' => now()->addYear(), 'purchase_price' => 6, 'sale_price' => 10, 'quantity' => 20, 'inventory_state' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function userWithPermission(string $name): User
    {
        return $this->userWithPermissions([$name]);
    }

    private function userWithPermissions(array $names): User
    {
        $role = DB::table('roles')->insertGetId(['name' => 'Closure Viewer '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('permissions')->whereIn('name', $names)->pluck('id') as $permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }

return User::factory()->create(['role' => $role, 'force_change_password' => false]);
    }
}
