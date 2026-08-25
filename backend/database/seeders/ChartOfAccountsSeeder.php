<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Assets', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1100', 'name' => 'Cash', 'parent_code' => '1000', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1200', 'name' => 'Bank', 'parent_code' => '1000', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1300', 'name' => 'Accounts Receivable', 'parent_code' => '1000', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1400', 'name' => 'Inventory Asset', 'parent_code' => '1000', 'account_type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2000', 'name' => 'Liabilities', 'account_type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2100', 'name' => 'Accounts Payable', 'parent_code' => '2000', 'account_type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '4000', 'name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '4100', 'name' => 'Sales Revenue', 'parent_code' => '4000', 'account_type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '5000', 'name' => 'Expenses', 'account_type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '5100', 'name' => 'Cost of Goods Sold', 'parent_code' => '5000', 'account_type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '5200', 'name' => 'Operating Expenses', 'parent_code' => '5000', 'account_type' => 'expense', 'normal_balance' => 'debit'],
        ];

        foreach ($accounts as $definition) {
            $parentId = isset($definition['parent_code'])
                ? Account::query()->where('code', $definition['parent_code'])->value('id')
                : null;

            Account::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'parent_id' => $parentId,
                    'account_type' => $definition['account_type'],
                    'normal_balance' => $definition['normal_balance'],
                    'active' => true,
                    'is_system' => true,
                ]
            );
        }
    }
}
