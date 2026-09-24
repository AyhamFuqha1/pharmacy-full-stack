<?php

namespace Database\Seeders;

use App\Models\AccountingPeriod;
use Illuminate\Database\Seeder;

class AccountingPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) now()->year;
        $start = now()->startOfYear()->toDateString();
        $end = now()->endOfYear()->toDateString();

        $overlapExists = AccountingPeriod::query()
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($overlapExists) {
            return;
        }

        AccountingPeriod::query()->create([
            'name' => (string) $year,
            'period_year' => $year,
            'period_month' => 0,
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'open',
        ]);
    }
}
