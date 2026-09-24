<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthorizationSeeder extends Seeder
{
    public const ROLE_PERMISSIONS = [
        'Administrator' => '*',
        'Accountant' => [
            'products.view',
            'accounting.reports',
            'accounting.vouchers',
            'purchase_returns.override_credit',
            'accounting.periods.manage',
            'dashboard.view',
            'audits.view',
            'deployment.readiness',
        ],
        'Cashier' => [
            'sales.create',
            'products.view',
            'customers.view',
            'dashboard.view',
        ],
        'Pharmacist' => [
            'products.view',
            'products.manage',
            'batches.manage',
            'dashboard.view',
            'patients.view',
            'patients.manage',
            'prescriptions.manage',
        ],
        'Normal User' => [
            'products.manage',
            'batches.manage',
            'sales.create',
            'dashboard.view',
            'patients.manage',
        ],
        'Sales & Inventory Operator' => [
            'sales.create',
            'products.view',
            'customers.view',
            'batches.view',
            'dashboard.view',
            'reports.view_operational',
            'reports.print_operational',
            'inventory.transfer',
        ],
    ];

    public const PERMISSIONS = [
        'sales.create',
        'products.view',
        'products.manage',
        'customers.view',
        'batches.manage',
        'users.manage',
        'accounting.reports',
        'accounting.vouchers',
        'accounting.periods.manage',
        'dashboard.view',
        'branches.manage',
        'patients.view',
        'patients.manage',
        'prescriptions.manage',
        'audits.view',
        'deployment.readiness',
        'reports.view_operational', 'reports.view_financial', 'reports.view_cost', 'reports.view_profit', 'reports.export', 'reports.print',
        'inventory.count', 'inventory.adjust', 'inventory.transfer',
        'inventory.units.manage', 'sales.discount', 'sales.override_price', 'sales.sell_below_cost',
        'customer.credit_override', 'cashier.shift', 'cashier.shift.close',
        'expenses.view', 'expenses.create', 'expenses.post',
        'other_income.view', 'other_income.create', 'other_income.post',
        'reconciliation.manage', 'controlled.view', 'controlled.dispense', 'controlled.audit',
        'imports.manage', 'system.backup', 'system.restore', 'attachments.manage', 'inventory.view_cost',
        'purchases.manage', 'suppliers.view', 'batches.view', 'reports.print_operational', 'purchase_returns.override_credit',
        'print_identity.manage',
    ];

    public function run(): void
    {
        DB::table('roles')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Administrator', 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('roles')->updateOrInsert(
            ['id' => 2],
            ['name' => 'Normal User', 'created_at' => now(), 'updated_at' => now()]
        );

        foreach (array_keys(self::ROLE_PERMISSIONS) as $roleName) {
            Role::query()->firstOrCreate(['name' => $roleName]);
        }

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName]);
        }

        $permissionIds = Permission::query()
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id', 'name');

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            $ids = $permissionNames === '*'
                ? $permissionIds->values()
                : $permissionIds->only($permissionNames)->values();

            $role->permissions()->sync($ids);
        }
    }
}
