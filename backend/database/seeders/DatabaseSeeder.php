<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::table('branches')->updateOrInsert(
            ['code' => 'MAIN'],
            ['name' => 'Main Branch', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        foreach (['tablet'=>'tab','strip'=>'strip','box'=>'box','bottle'=>'btl','piece'=>'pc'] as $name=>$symbol) {
            DB::table('units')->updateOrInsert(['name'=>$name], ['symbol'=>$symbol,'created_at'=>now(),'updated_at'=>now()]);
        }

        $this->call([
            AuthorizationSeeder::class,
            ChartOfAccountsSeeder::class,
            AccountingPeriodSeeder::class,
        ]);
    }
}
