<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UniversalReportCustomerAgingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $mainBranch;
    private int $otherBranch;
    private int $customerA;
    private int $customerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->mainBranch = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->otherBranch = DB::table('branches')->insertGetId([
            'name' => 'Aging Branch', 'code' => 'AGING', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'role' => 1, 'branch_id' => $this->mainBranch, 'force_change_password' => false,
        ]);
        $this->actingAs($this->admin, 'sanctum');
        $this->customerA = $this->customer('Aging Customer A', 'AGING-A');
        $this->customerB = $this->customer('Aging Customer B', 'AGING-B');
        $this->invoice($this->customerA, $this->mainBranch, '2026-01-01', '2026-01-10', 100, 'SAL-AGING-A');
        $this->invoice($this->customerB, $this->otherBranch, '2026-02-01', '2026-02-10', 60, 'SAL-AGING-B');
    }

    public function test_ar_aging_loads_without_customer_and_uses_authoritative_projection(): void
    {
        $response = $this->query([])->assertOk()
            ->assertJsonPath('data.report.key', 'ar_aging')
            ->assertJsonPath('data.summary.total_outstanding', 160)
            ->assertJsonPath('data.summary.over_90', 160);

        $this->assertEqualsCanonicalizing(
            ['Aging Customer A', 'Aging Customer B'],
            collect($response->json('data.rows'))->pluck('party_name')->all(),
        );
    }

    public function test_customer_branch_and_as_of_filters_are_forwarded_to_aging_service(): void
    {
        $this->query(['party_id' => $this->customerA, 'as_of' => '2026-09-17'])
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.party_id', $this->customerA)
            ->assertJsonPath('data.rows.0.outstanding_amount', 100);

        $this->query(['branch_id' => $this->otherBranch, 'as_of' => '2026-09-17'])
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.party_id', $this->customerB);

        $this->query(['party_id' => $this->customerB, 'as_of' => '2026-01-15'])
            ->assertOk()
            ->assertJsonCount(0, 'data.rows')
            ->assertJsonPath('data.summary.total_outstanding', 0);
    }

    public function test_frontend_clears_stale_data_and_rejects_obsolete_report_requests(): void
    {
        $page = file_get_contents(base_path('../frontend/src/app/pages/UniversalReportsPage.tsx'));
        $api = file_get_contents(base_path('../frontend/src/api/universalReports.ts'));

        $this->assertStringContainsString('reportRequest.current?.controller.abort()', $page);
        $this->assertStringContainsString('setResult(null);', $page);
        $this->assertStringContainsString('reportRequest.current?.id === requestId', $page);
        $this->assertStringContainsString('controller.signal', $page);
        $this->assertStringContainsString('error ? null : result', $page);
        $this->assertStringContainsString('[page, sort, direction, reportKey]', $page);
        $this->assertStringContainsString('const requested = params.get("report")', $page);
        $this->assertStringNotContainsString('find((item) => item.key === reportKey) ||', $page);
        $this->assertStringContainsString('configuration, signal)', $api);

        $controller = file_get_contents(app_path('Http/Controllers/Api/ReportExplorerController.php'));
        $service = file_get_contents(app_path('Services/ReportExplorerService.php'));
        $this->assertStringContainsString("'page'=>'nullable|integer|min:1'", $controller);
        $this->assertMatchesRegularExpression("/'per_page'\s*=>\s*\\\$input\['per_page'\]\s*\?\?\s*25/", $service);
    }

    public function test_aging_defaults_hide_internal_ids_and_csv_is_readable_in_arabic(): void
    {
        $catalog = $this->getJson('/api/reports/catalog')->assertOk();
        $aging = collect($catalog->json('data'))->firstWhere('key', 'ar_aging');
        $this->assertNotContains('party_id', $aging['default_columns']);
        $this->assertNotContains('invoice_id', $aging['default_columns']);
        $this->assertContains('party_name', $aging['default_columns']);
        $this->assertContains('invoice_number', $aging['default_columns']);

        $response = $this->withHeader('Accept-Language', 'ar')->post('/api/reports/export/csv', [
            'report_key' => 'ar_aging',
            'filters' => ['party_id' => $this->customerA, 'as_of' => '2026-09-17'],
            'columns' => ['party_name', 'invoice_number', 'invoice_date', 'due_date', 'bucket'],
            'sort' => 'due_date',
            'direction' => 'asc',
        ]);
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('العميل', $csv);
        $this->assertStringContainsString('رقم الفاتورة', $csv);
        $this->assertStringContainsString('2026/01/01', $csv);
        $this->assertStringContainsString('2026/01/10', $csv);
        $this->assertStringContainsString('أكثر من 90 يوم', $csv);
        $this->assertStringNotContainsString('party_id', $csv);
        $this->assertStringNotContainsString('invoice_id', $csv);
    }

    private function query(array $filters)
    {
        return $this->postJson('/api/reports/query', [
            'report_key' => 'ar_aging',
            'filters' => $filters,
            'columns' => ['party_id', 'party_name', 'aging_basis', 'invoice_id', 'invoice_number', 'invoice_date', 'due_date', 'original_amount', 'settled_amount', 'outstanding_amount', 'age_days', 'bucket'],
            'sort' => 'due_date',
            'direction' => 'asc',
            'per_page' => 25,
        ]);
    }

    private function customer(string $name, string $phone): int
    {
        return DB::table('customers')->insertGetId([
            'name' => $name, 'phone_num' => $phone, 'city' => 'Test', 'dept' => 0,
        ]);
    }

    private function invoice(int $customer, int $branch, string $date, string $dueDate, float $total, string $number): int
    {
        return DB::table('sales_invoices')->insertGetId([
            'document_number' => $number, 'customer_id' => $customer, 'user_id' => $this->admin->id,
            'branch_id' => $branch, 'total_price' => $total, 'discount' => 0, 'paid_amount' => 0,
            'payment_method' => 'cash', 'profit' => 0, 'date' => $date, 'due_date' => $dueDate,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
