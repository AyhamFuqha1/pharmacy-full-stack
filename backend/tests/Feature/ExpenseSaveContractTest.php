<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseSaveContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $branchId;
    private int $expenseAccountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->branchId = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->expenseAccountId = (int) DB::table('accounts')->where('code', '5200')->value('id');
        $this->user = User::factory()->create(['role' => 1, 'force_change_password' => false, 'branch_id' => $this->branchId]);
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_cash_expense_uses_explicit_business_date_and_posts_expense_against_cash(): void
    {
        $response = $this->postJson('/api/expenses', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-09-12')
            ->assertJsonPath('data.payment_method', 'cash');

        $expenseId = (int) $response->json('data.id');
        $this->assertDatabaseHas('expenses', [
            'id' => $expenseId,
            'date' => '2026-09-12',
            'payment_method' => 'cash',
            'type' => 'cash',
            'amount' => 25,
        ]);

        $entry = DB::table('journal_entries as entries')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->where('vouchers.source_type', 'expense')
            ->where('vouchers.source_id', $expenseId)
            ->select('entries.*')
            ->first();
        $this->assertNotNull($entry);
        $this->assertSame('2026-09-12', substr($entry->entry_date, 0, 10));

        $lines = DB::table('journal_entry_lines as lines')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('lines.journal_entry_id', $entry->id)
            ->get(['accounts.code', 'lines.side', 'lines.amount']);
        $this->assertTrue($lines->contains(fn ($line) => $line->code === '5200' && $line->side === 'debit' && (float) $line->amount === 25.0));
        $this->assertTrue($lines->contains(fn ($line) => $line->code === '1100' && $line->side === 'credit' && (float) $line->amount === 25.0));
        $this->assertDatabaseHas('cash_movements', ['source_type' => 'expense', 'source_id' => $expenseId, 'direction' => 'out', 'amount' => 25]);
    }

    public function test_missing_expense_date_returns_localized_422_without_partial_rows(): void
    {
        $before = $this->accountingCounts();
        $payload = $this->validPayload();
        unset($payload['expense_date']);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/expenses', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.expense_date.0', 'تاريخ المصروف مطلوب.');

        $this->assertSame($before, $this->accountingCounts());
    }

    public function test_missing_payment_type_returns_localized_422_without_default_or_partial_rows(): void
    {
        $before = $this->accountingCounts();
        $payload = $this->validPayload();
        unset($payload['payment_type']);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/expenses', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_type.0', 'طريقة الدفع مطلوبة.');

        $this->assertSame($before, $this->accountingCounts());
    }

    private function validPayload(): array
    {
        return [
            'branch_id' => $this->branchId,
            'expense_date' => '2026-09-12',
            'expense_account_id' => $this->expenseAccountId,
            'amount' => 25,
            'payment_type' => 'cash',
            'description' => 'اختبار مصروف كهرباء',
            'external_reference' => null,
        ];
    }

    private function accountingCounts(): array
    {
        return collect(['expenses', 'vouchers', 'journal_entries', 'journal_entry_lines', 'cash_movements'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
}
