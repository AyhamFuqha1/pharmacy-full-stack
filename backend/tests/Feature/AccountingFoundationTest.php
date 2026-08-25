<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Voucher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_schema_and_relationships_are_available(): void
    {
        $user = User::factory()->create();

        $period = AccountingPeriod::create([
            'name' => 'August 2026',
            'period_year' => 2026,
            'period_month' => 8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
        ]);

        $cash = Account::create([
            'code' => '9000',
            'name' => 'Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'active' => true,
            'is_system' => true,
        ]);

        $sales = Account::create([
            'code' => '9400',
            'name' => 'Sales Revenue',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'active' => true,
            'is_system' => true,
        ]);

        $voucher = Voucher::create([
            'voucher_type' => 'sales_invoice',
            'source_type' => 'sales_invoice',
            'source_id' => 1,
            'voucher_number' => 'INV-2026-001',
            'reference_number' => 'SI-001',
            'accounting_period_id' => $period->id,
            'user_id' => $user->id,
            'status' => 'posted',
            'notes' => 'Sales posting',
        ]);

        $entry = JournalEntry::create([
            'voucher_id' => $voucher->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-2026-001',
            'entry_date' => '2026-08-10',
            'description' => 'Cash sale',
            'status' => 'posted',
            'total_debit' => 0,
            'total_credit' => 0,
            'created_by' => $user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'side' => 'debit',
            'amount' => 150.00,
            'description' => 'Cash received',
            'entity_type' => 'sales_invoice',
            'entity_id' => 1,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $sales->id,
            'side' => 'credit',
            'amount' => 150.00,
            'description' => 'Sales revenue',
            'entity_type' => 'sales_invoice',
            'entity_id' => 1,
        ]);

        $entry->refreshTotals();

        $this->assertSame(150.00, (float) $entry->total_debit);
        $this->assertSame(150.00, (float) $entry->total_credit);
        $this->assertTrue($entry->isBalanced());

        $this->assertSame($period->id, $voucher->accountingPeriod->id);
        $this->assertSame($user->id, $voucher->user->id);
        $this->assertCount(2, $entry->lines);
        $this->assertSame($cash->id, $entry->lines()->first()->account_id);
    }

    public function test_account_hierarchy_relationships_are_supported(): void
    {
        $cash = Account::create([
            'code' => '9010',
            'name' => 'Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'active' => true,
            'is_system' => true,
        ]);

        $bank = Account::create([
            'code' => '9011',
            'name' => 'Bank',
            'parent_id' => $cash->id,
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'active' => true,
            'is_system' => true,
        ]);

        $this->assertSame($cash->id, $bank->parent->id);
        $this->assertCount(1, $cash->children);
        $this->assertSame('Bank', $cash->children()->first()->name);
    }
}
