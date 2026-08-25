<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Permission;
use App\Models\Role;

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

        $roles = [
            ['id' => 1, 'name' => 'Administrator'],
            ['id' => 2, 'name' => 'Normal User'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(['id' => $role['id']], ['name' => $role['name'], 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (['Pharmacist', 'Cashier', 'Accountant'] as $name) {
            Role::query()->updateOrCreate(['name' => $name]);
        }

        $permissions = [
            'sales.create', 'products.manage', 'batches.manage', 'users.manage',
            'accounting.reports', 'accounting.vouchers', 'dashboard.view',
            'branches.manage', 'patients.manage', 'prescriptions.manage', 'audits.view', 'deployment.readiness',
        ];
        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(['name' => $permission]);
        }

        $all = Permission::query()->pluck('id', 'name');
        Role::query()->where('name', 'Administrator')->first()->permissions()->sync($all->values());
        Role::query()->where('name', 'Pharmacist')->first()->permissions()->sync($all->only(['products.manage', 'batches.manage', 'dashboard.view', 'patients.manage', 'prescriptions.manage'])->values());
        Role::query()->where('name', 'Cashier')->first()->permissions()->sync($all->only(['sales.create', 'dashboard.view'])->values());
        Role::query()->where('name', 'Accountant')->first()->permissions()->sync($all->only(['accounting.reports', 'accounting.vouchers', 'dashboard.view', 'audits.view', 'deployment.readiness'])->values());
        Role::query()->where('name', 'Normal User')->first()->permissions()->sync($all->only(['products.manage', 'batches.manage', 'sales.create', 'dashboard.view', 'patients.manage'])->values());

        $this->call(ChartOfAccountsSeeder::class);
    }
}
