<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialReportLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_presentation_does_not_rewrite_historical_journals_or_accounting_values(): void
    {
        $this->seed(\Database\Seeders\ChartOfAccountsSeeder::class);
        $user = User::factory()->create();
        AccountingPeriod::create([
            'name' => 'September 2026',
            'period_year' => 2026,
            'period_month' => 9,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
        ]);
        $provider = DB::table('providers')->insertGetId([
            'name' => 'Localization Supplier',
            'phone_num' => '01000000009',
            'city' => 'Test',
            'dept' => 0,
        ]);
        $invoice = DB::table('purchase_invoices')->insertGetId([
            'provider_id' => $provider,
            'user_id' => $user->id,
            'amount' => 75,
            'descount' => 0,
            'date' => '2026-09-03',
        ]);
        app(AccountingPostingService::class)->postPurchaseInvoice($invoice);

        $before = DB::table('journal_entry_lines')->orderBy('id')->get()->map(fn ($line) => (array) $line)->all();
        $ledger = app(AccountingReportService::class)->generalLedger();
        $after = DB::table('journal_entry_lines')->orderBy('id')->get()->map(fn ($line) => (array) $line)->all();

        $this->assertSame($before, $after);
        $this->assertCount(2, $ledger);
        $this->assertSame(['debit', 'credit'], $ledger->pluck('side')->all());
        $this->assertSame([75.0, 75.0], $ledger->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        $this->assertSame(['Inventory purchase', 'Inventory purchase'], $ledger->pluck('description')->all());
        $this->assertSame(['1400', '2100'], $ledger->pluck('code')->all());
    }

    public function test_frontend_uses_exact_stable_financial_display_mappings(): void
    {
        $presentation = file_get_contents(base_path('../frontend/src/utils/financialPresentation.ts'));
        $reports = file_get_contents(base_path('../frontend/src/app/pages/ReportsPage.tsx'));
        $formatters = file_get_contents(base_path('../frontend/src/utils/batchFormatters.ts'));
        $translations = file_get_contents(base_path('../frontend/src/i18n/resources.ts'));

        $this->assertStringContainsString('rule.sources.includes(sourceType) && rule.descriptions.includes(description)', $presentation);
        $this->assertStringContainsString('seededName === storedName ? t(seededName) : storedName', $presentation);
        $this->assertStringContainsString('{ sources: ["other_income"], descriptions: ["Income received"] }', $presentation);
        $this->assertStringNotContainsString('descriptions: ["Other income", "Income received"]', $presentation);
        $this->assertStringContainsString('financialDescription(', $reports);
        $this->assertStringContainsString('financialAccountName(', $reports);
        $this->assertStringContainsString("i18n.resolvedLanguage === 'ar' ? 'ar' : 'en'", $formatters);
        $this->assertStringContainsString('"Outstanding receivable": "ذمم مستحقة على العميل"', $translations);
        $this->assertStringContainsString('"Sales Revenue": "إيرادات المبيعات"', $translations);
    }
}
