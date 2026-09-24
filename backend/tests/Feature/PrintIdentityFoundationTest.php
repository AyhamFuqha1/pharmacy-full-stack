<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PrintIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintIdentityFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false, 'branch_id' => DB::table('branches')->value('id')]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_administrator_can_read_update_and_audit_structured_identity(): void
    {
        $this->getJson('/api/print-identity')->assertOk()->assertJsonStructure(['data' => ['fields', 'logo', 'custom_fields']]);
        $payload = $this->payload('شركة أجمل', '111');
        $payload['fields']['tax_number'] = ['value' => 'TAX-7', 'visible' => false, 'order' => 3];
        $payload['custom_fields'] = [
            ['label' => 'رقم ترخيص وزارة الصحة', 'value' => '123456', 'visible' => true, 'order' => 12],
            ['label' => 'حقل مخفي', 'value' => 'سري', 'visible' => false, 'order' => 13],
        ];
        $this->post('/api/print-identity', ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.fields.business_name.value', 'شركة أجمل')->assertJsonPath('data.custom_fields.0.label', 'رقم ترخيص وزارة الصحة');

        $resolved = app(PrintIdentityService::class)->currentSnapshot();
        $this->assertFalse(collect($resolved['fields'])->contains('key', 'tax_number'));
        $this->assertTrue(collect($resolved['fields'])->contains(fn ($field) => ($field['label'] ?? null) === 'رقم ترخيص وزارة الصحة'));
        $this->assertFalse(collect($resolved['fields'])->contains(fn ($field) => ($field['label'] ?? null) === 'حقل مخفي'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->admin->id, 'action_type' => 'print_identity_update']);
    }

    public function test_operational_user_cannot_read_or_modify_identity(): void
    {
        $role = DB::table('roles')->where('name', 'Sales & Inventory Operator')->value('id');
        $operator = User::factory()->create(['role' => $role, 'force_change_password' => false]);
        $this->actingAs($operator, 'sanctum');
        $this->getJson('/api/print-identity')->assertForbidden();
        $this->postJson('/api/print-identity', ['payload' => json_encode($this->payload('Unsafe', '9'))])->assertForbidden();
    }

    public function test_logo_is_validated_stored_privately_and_api_never_exposes_paths(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $response = $this->post('/api/print-identity', [
            'payload' => json_encode($this->payload('Logo Pharmacy', '111')),
            'logo' => UploadedFile::fake()->createWithContent('logo.png', $png),
        ], ['Accept' => 'application/json'])->assertOk();
        $data = $response->json('data');
        $this->assertStringStartsWith('/api/print-identity/logo/', $data['logo']['url']);
        $response->assertJsonMissingPath('data.logo.asset_id')->assertJsonMissingPath('data.logo.storage_key')->assertJsonMissingPath('data.logo.disk');
        $this->get($data['logo']['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->post('/api/print-identity', [
            'payload' => json_encode($this->payload('Logo Pharmacy', '111')),
            'logo' => UploadedFile::fake()->createWithContent('logo.php', $png),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');
    }

    public function test_sales_capture_immutable_identity_and_legacy_print_uses_non_mutating_fallback(): void
    {
        $this->save($this->payload('Snapshot Pharmacy', '111'));
        [$product, $customer, $unit] = $this->saleFixtures();
        $first = $this->postJson('/api/sales-invoices', $this->salePayload($product, $customer, $unit))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('document_print_identity_snapshots', ['document_type' => 'sales_invoice', 'document_id' => $first]);
        $this->assertSame(9.0, (float) DB::table('sales_invoices')->where('id', $first)->value('total_price'));

        $this->save($this->payload('Snapshot Pharmacy', '222'));
        $firstIdentity = $this->getJson("/api/print-documents/sales_invoice/$first")->assertOk()->json('data.meta.print_identity');
        $this->assertSame('document_snapshot', $firstIdentity['source']);
        $this->assertSame('111', collect($firstIdentity['fields'])->firstWhere('key', 'phone')['value']);

        $second = $this->postJson('/api/sales-invoices', $this->salePayload($product, $customer, $unit))->assertCreated()->json('data.id');
        $secondIdentity = $this->getJson("/api/print-documents/sales_invoice/$second")->assertOk()->json('data.meta.print_identity');
        $this->assertSame('222', collect($secondIdentity['fields'])->firstWhere('key', 'phone')['value']);

        $legacy = $this->legacySale($product, $customer);
        $legacyIdentity = $this->getJson("/api/print-documents/sales_invoice/$legacy")->assertOk()->json('data.meta.print_identity');
        $this->assertSame('current_legacy_fallback', $legacyIdentity['source']);
        $this->assertSame('222', collect($legacyIdentity['fields'])->firstWhere('key', 'phone')['value']);
        $this->assertDatabaseMissing('document_print_identity_snapshots', ['document_type' => 'sales_invoice', 'document_id' => $legacy]);
    }

    public function test_sales_invoice_print_renderer_uses_resolved_identity_not_legacy_app_name(): void
    {
        $source = file_get_contents(base_path('../frontend/src/app/pages/AdminOperationalPages.tsx'));
        $start = strpos($source, 'function SaleInvoicePrint');
        $end = strpos($source, 'export function ActionableAlerts');
        $renderer = substr($source, $start, $end - $start);
        $this->assertStringContainsString('<PrintIdentityHeader identity={printIdentity} />', $renderer);
        $this->assertStringContainsString('meta.print_identity', $renderer);
        $this->assertStringNotContainsString('pharmacy.name', $renderer);
        $this->assertStringNotContainsString('t("Pharmacy ERP")', $renderer);
    }

    public function test_purchase_invoice_has_a_dedicated_print_safe_identity_renderer(): void
    {
        $source = file_get_contents(base_path('../frontend/src/app/pages/AdminOperationalPages.tsx'));
        $start = strpos($source, 'function PurchaseInvoicePrint');
        $end = strpos($source, 'export function ActionableAlerts');
        $renderer = substr($source, $start, $end - $start);

        $this->assertStringContainsString('<PrintIdentityHeader identity={printIdentity} />', $renderer);
        $this->assertStringContainsString('meta.print_identity', $renderer);
        $this->assertStringContainsString('supplier.name', $renderer);
        $this->assertStringContainsString('content.rows_lines', $renderer);
        $this->assertStringContainsString('content.totals_summary', $renderer);
        $this->assertStringContainsString('purchase-invoice-table-wrap', $renderer);
        $this->assertStringNotContainsString('overflow-x-auto', $renderer);
        $this->assertStringNotContainsString('ROW COUNT', $renderer);
        $this->assertStringNotContainsString('DATA', $renderer);
        $this->assertStringNotContainsString('selling_price', $renderer);
        $this->assertStringNotContainsString('opening_balance_stock', $renderer);
        $this->assertStringNotContainsString('closing_balance_stock', $renderer);
        $this->assertStringNotContainsString('source_references', $renderer);
        $this->assertStringNotContainsString('pharmacy.name', $renderer);
        $this->assertStringNotContainsString('t("Pharmacy ERP")', $renderer);
    }

    public function test_sales_return_has_a_dedicated_print_safe_identity_renderer(): void
    {
        $source = file_get_contents(base_path('../frontend/src/app/pages/AdminOperationalPages.tsx'));
        $start = strpos($source, 'function SalesReturnPrint');
        $end = strpos($source, 'export function ActionableAlerts');
        $renderer = substr($source, $start, $end - $start);

        $this->assertStringContainsString('<PrintIdentityHeader identity={printIdentity} />', $renderer);
        $this->assertStringContainsString('meta.print_identity', $renderer);
        $this->assertStringContainsString('originalSale.document_number', $renderer);
        $this->assertStringContainsString('item.customer_credit_value', $renderer);
        $this->assertStringContainsString('item.return_to_inventory', $renderer);
        $this->assertStringContainsString('sales-return-table-wrap', $renderer);
        $this->assertStringNotContainsString('overflow-x-auto', $renderer);
        $this->assertStringNotContainsString('ROW COUNT', $renderer);
        $this->assertStringNotContainsString('DATA', $renderer);
        $this->assertStringNotContainsString('opening_balance_stock', $renderer);
        $this->assertStringNotContainsString('closing_balance_stock', $renderer);
        $this->assertStringNotContainsString('source_references', $renderer);
        $this->assertStringNotContainsString('pharmacy.name', $renderer);
        $this->assertStringNotContainsString('t("Pharmacy ERP")', $renderer);
    }

    public function test_customer_statement_print_payload_is_compact_and_uses_current_identity(): void
    {
        $this->save($this->payload('Statement Pharmacy', '555'));
        $customer = DB::table('customers')->insertGetId(['name' => 'Statement Customer', 'phone_num' => '0599000000', 'city' => 'Test']);

        $data = $this->postJson('/api/print-documents/customer_statement', ['customer_id' => $customer])->assertOk()->json('data');

        $this->assertSame('Statement Pharmacy', $data['meta']['print_identity']['business_name']);
        $this->assertSame('Statement Customer', $data['content']['party']['name']);
        $this->assertSame('0599000000', $data['content']['party']['phone_num']);
        $this->assertSame([], $data['content']['filters_used']);
        $this->assertSame(['opening_balance', 'total_debit', 'total_credit', 'closing_balance'], array_keys($data['content']['totals_summary']));
        $this->assertArrayNotHasKey('created_by_name', $data['content']['rows_lines'][0] ?? []);
    }

    public function test_customer_statement_has_a_dedicated_print_safe_renderer(): void
    {
        $source = file_get_contents(base_path('../frontend/src/app/pages/AdminOperationalPages.tsx'));
        $start = strpos($source, 'function CustomerStatementPrint');
        $end = strpos($source, 'function SupplierStatementPrint');
        $renderer = substr($source, $start, $end - $start);

        $this->assertStringContainsString('<PrintIdentityHeader identity={identity} />', $renderer);
        $this->assertStringContainsString('row.source_label', $renderer);
        $this->assertStringContainsString('row.running_balance', $renderer);
        $this->assertStringContainsString('totals.closing_balance', $renderer);
        $this->assertStringNotContainsString('created_by_name', $renderer);
        $this->assertStringNotContainsString('t("Pharmacy ERP")', $renderer);
        $this->assertStringNotContainsString('overflow-x-auto', $renderer);
    }

    public function test_supplier_statement_print_payload_is_compact_and_uses_current_identity(): void
    {
        $this->save($this->payload('Supplier Statement Pharmacy', '555'));
        $supplier = DB::table('providers')->insertGetId(['name' => 'Statement Supplier', 'phone_num' => '0599111111', 'city' => 'Test']);

        $data = $this->postJson('/api/print-documents/supplier_statement', ['provider_id' => $supplier])->assertOk()->json('data');

        $this->assertSame('Supplier Statement Pharmacy', $data['meta']['print_identity']['business_name']);
        $this->assertSame('Statement Supplier', $data['content']['party']['name']);
        $this->assertSame('0599111111', $data['content']['party']['phone_num']);
        $this->assertSame([], $data['content']['filters_used']);
        $this->assertSame(['opening_balance', 'total_debit', 'total_credit', 'closing_balance'], array_keys($data['content']['totals_summary']));
        $this->assertSame($data['content']['opening_balance_stock'], $data['content']['totals_summary']['opening_balance']);
        $this->assertSame($data['content']['closing_balance_stock'], $data['content']['totals_summary']['closing_balance']);
        $this->assertArrayNotHasKey('created_by_name', $data['content']['rows_lines'][0] ?? []);
    }

    public function test_supplier_statement_has_a_dedicated_business_readable_print_renderer(): void
    {
        $source = file_get_contents(base_path('../frontend/src/app/pages/AdminOperationalPages.tsx'));
        $start = strpos($source, 'function SupplierStatementPrint');
        $end = strpos($source, 'type PrintField');
        $renderer = substr($source, $start, $end - $start);

        $this->assertStringContainsString('<PrintIdentityHeader identity={identity} />', $renderer);
        $this->assertStringContainsString('row.source_label', $renderer);
        $this->assertStringContainsString('row.credit', $renderer);
        $this->assertStringContainsString('row.debit', $renderer);
        $this->assertStringContainsString('row.running_balance', $renderer);
        $this->assertStringContainsString('totals.closing_balance', $renderer);
        $this->assertStringContainsString('purchase_invoice: "Purchase invoice"', $renderer);
        $this->assertStringNotContainsString('row.description)', $renderer);
        $this->assertStringNotContainsString('created_by_name', $renderer);
        $this->assertStringNotContainsString('BALANCE SEMANTICS', $renderer);
        $this->assertStringNotContainsString('PERIOD CHANGE', $renderer);
        $this->assertStringNotContainsString('overflow-x-auto', $renderer);
        $this->assertStringNotContainsString('t("Pharmacy ERP")', $renderer);
    }

    public function test_every_supported_transaction_print_title_resolves_to_an_identity_renderer(): void
    {
        $source = file_get_contents(base_path('../frontend/src/app/pages/AdminOperationalPages.tsx'));
        foreach ([
            'Purchase Return' => 'PurchaseReturnPrint',
            'Customer Receipt' => 'CustomerReceiptPrint',
            'Supplier Payment' => 'SupplierPaymentPrint',
            'Cheque' => 'ChequePrint',
            'Expense' => 'ExpensePrint',
            'Other Income' => 'OtherIncomePrint',
            'Stock Transfer' => 'StockTransferPrint',
            'Stock Adjustment' => 'StockAdjustmentPrint',
            'Stock Count' => 'StockCountPrint',
            'Opening Balance' => 'OpeningBalancePrint',
            'Cashier Shift Close' => 'CashierShiftPrint',
        ] as $title => $renderer) {
            $this->assertStringContainsString($title, $source);
            $this->assertStringContainsString($renderer, $source);
        }

        $start = strpos($source, 'function BusinessTransactionPrint');
        $end = strpos($source, 'function SafeReportDocumentPrint');
        $renderers = substr($source, $start, $end - $start);
        $this->assertStringContainsString('<PrintIdentityHeader identity={identity} />', $renderers);
        $this->assertStringContainsString('meta.document_number', $renderers);
        $this->assertStringNotContainsString('meta.pharmacy', $renderers);
        $this->assertStringNotContainsString('Pharmacy ERP', $renderers);
        $this->assertStringNotContainsString('source_references', $renderers);
        $this->assertStringNotContainsString('overflow-x-auto', $renderers);
    }

    public function test_report_printing_uses_print_identity_without_technical_summary_fields(): void
    {
        $renderer = file_get_contents(base_path('../frontend/src/app/components/PrintableReport.tsx'));
        $service = file_get_contents(app_path('Services/ReportExplorerService.php'));

        $this->assertStringContainsString('<PrintIdentityHeader identity={data.print_identity || {}} />', $renderer);
        $this->assertStringNotContainsString('data.pharmacy', $renderer);
        $this->assertStringNotContainsString('Pharmacy ERP', $renderer);
        $this->assertStringContainsString("'print_identity' => app(PrintIdentityService::class)->currentPrintable()", $service);
        $this->assertStringNotContainsString("'row_count' =>", $service);
        $this->assertStringNotContainsString("config('app.name'", file_get_contents(app_path('Services/PrintIdentityService.php')));
    }

    private function payload(string $name, string $phone): array
    {
        $fields = [];
        foreach (PrintIdentityService::FIELD_KEYS as $index => $key) $fields[$key] = ['value' => $key === 'business_name' ? $name : ($key === 'phone' ? $phone : ''), 'visible' => true, 'order' => $index + 2];
        return ['fields' => $fields, 'logo_visible' => true, 'logo_order' => 1, 'remove_logo' => false, 'custom_fields' => []];
    }

    private function save(array $payload): void
    {
        $this->post('/api/print-identity', ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)], ['Accept' => 'application/json'])->assertOk();
    }

    private function saleFixtures(): array
    {
        $product = Product::factory()->create(['barcode' => 'SNAPSHOT-P']);
        $customer = DB::table('customers')->insertGetId(['name' => 'Snapshot Customer', 'phone_num' => 'SNAP-C', 'city' => 'Test']);
        $unit = $this->postJson("/api/products/$product->id/units", ['name' => 'Tablet', 'is_base' => true, 'sale_price' => 9])->assertCreated()->json('data.unit_id');
        DB::table('product_batches')->insert(['product_id' => $product->id, 'branch_id' => DB::table('branches')->value('id'), 'batch_number' => 'SNAP-B', 'expiry_date' => now()->addYear(), 'purchase_price' => 2, 'sale_price' => 9, 'quantity' => 10, 'inventory_state' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        return [$product->id, $customer, $unit];
    }

    private function salePayload(int $product, int $customer, int $unit): array
    {
        return ['customer_id' => $customer, 'date' => now()->toDateString(), 'paid_amount' => 9, 'payment_method' => 'cash', 'items' => [['product_id' => $product, 'unit_id' => $unit, 'amount' => 1]]];
    }

    private function legacySale(int $product, int $customer): int
    {
        $batch = DB::table('product_batches')->where('product_id', $product)->value('id');
        $sale = DB::table('sales_invoices')->insertGetId(['document_number' => 'SAL-LEGACY-PI', 'branch_id' => DB::table('branches')->value('id'), 'customer_id' => $customer, 'user_id' => $this->admin->id, 'total_price' => 9, 'discount' => 0, 'paid_amount' => 9, 'payment_method' => 'cash', 'profit' => 7, 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prod_sales')->insert(['sales_invoice_id' => $sale, 'batch_id' => $batch, 'amount' => 1, 'price' => 9, 'discount' => 0, 'cost_price' => 2, 'line_total' => 9, 'invoice_discount_share' => 0, 'final_total' => 9, 'profit' => 7, 'created_at' => now(), 'updated_at' => now()]);
        return $sale;
    }
}
