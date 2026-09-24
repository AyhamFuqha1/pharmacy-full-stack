<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BusinessTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierShiftDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_new_shift_has_readable_cashier_consistent_timestamps_and_unchanged_cash_totals(): void
    {
        $this->seed();
        $branch = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $expenseAccount = (int) DB::table('accounts')->where('code', '5200')->value('id');
        $incomeAccount = (int) DB::table('accounts')->where('code', '4200')->value('id');
        $cashier = User::factory()->create(['name' => 'ayham', 'role' => 1, 'force_change_password' => false, 'branch_id' => $branch]);
        $this->actingAs($cashier, 'sanctum');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 15:30:00', 'UTC'));
        $shiftId = (int) $this->postJson('/api/cashier-shifts/open', ['branch_id' => $branch, 'opening_cash' => 100])
            ->assertCreated()->assertJsonPath('data.cashier_name', 'ayham')->json('data.id');
        $persistedOpenedAt = DB::table('cashier_shifts')->where('id', $shiftId)->value('opened_at');
        $this->assertSame('2026-09-12 15:30:00', $persistedOpenedAt);

        $this->postJson('/api/other-incomes', ['date' => '2026-09-12', 'branch_id' => $branch, 'account_id' => $incomeAccount, 'amount' => 20, 'payment_method' => 'cash', 'description' => 'Cash in'])->assertCreated();
        $this->postJson('/api/expenses', ['expense_date' => '2026-09-12', 'branch_id' => $branch, 'expense_account_id' => $expenseAccount, 'amount' => 5, 'payment_type' => 'cash', 'description' => 'Cash out'])->assertCreated();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 15:35:00', 'UTC'));
        $closed = $this->postJson("/api/cashier-shifts/$shiftId/close", ['counted_cash' => 115])
            ->assertOk()
            ->assertJsonPath('data.cashier_name', 'ayham')
            ->assertJsonPath('data.cash_in', 20)
            ->assertJsonPath('data.cash_out', 5)
            ->assertJsonPath('data.expected_cash', 115)
            ->assertJsonPath('data.counted_cash', 115)
            ->assertJsonPath('data.difference', 0);

        $stored = DB::table('cashier_shifts')->find($shiftId);
        $this->assertLessThanOrEqual($stored->closed_at, $stored->opened_at);
        $this->assertSame($persistedOpenedAt, $stored->opened_at, 'Closing a shift must preserve its original opened_at.');
        $this->assertSame('2026-09-12 15:35:00', $stored->closed_at);

        $time = app(BusinessTimeService::class);
        $this->assertSame($time->toBusinessTimestamp($stored->opened_at), $closed->json('data.opened_at'));
        $this->assertSame($time->toBusinessTimestamp($stored->closed_at), $closed->json('data.closed_at'));
        $this->getJson('/api/cashier-shifts')->assertOk()->assertJsonPath('data.0.cashier_name', 'ayham');
    }

    public function test_cashier_history_ui_uses_name_and_ids_are_excluded_from_currency_formatting(): void
    {
        $page = file_get_contents(base_path('../frontend/src/app/pages/FriendlyOperationalPages.tsx'));
        $table = file_get_contents(base_path('../frontend/src/app/components/ErpPrimitives.tsx'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/FinanceExpansionController.php'));

        $this->assertStringContainsString('"cashier_name"', $page);
        $this->assertStringContainsString('/(^id$|_id$)/i.test(k)', $table);
        $this->assertStringNotContainsString('cashier_id", "status', $page);
        $this->assertStringContainsString('utcTimestamp()', $controller);
        $this->assertDoesNotMatchRegularExpression('/(?:opened_at|closed_at).{0,120}(?:addHours|subHours|[+-]\s*3)/s', $controller);
    }
}
