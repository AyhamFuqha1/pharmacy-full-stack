<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['name' => 'customers.view', 'description' => 'Read customers for operational document lookup', 'created_at' => now(), 'updated_at' => now()]);
        $cashierId = DB::table('roles')->where('name', 'Cashier')->value('id');
        if (!$cashierId) return;
        $permissionIds = DB::table('permissions')->whereIn('name', ['products.view', 'customers.view'])->pluck('id');
        foreach ($permissionIds as $permissionId) DB::table('role_permissions')->insertOrIgnore(['role_id' => $cashierId, 'permission_id' => $permissionId]);
    }

    public function down(): void
    {
        $customersViewId = DB::table('permissions')->where('name', 'customers.view')->value('id');
        if ($customersViewId) {
            DB::table('role_permissions')->where('permission_id', $customersViewId)->delete();
            DB::table('permissions')->where('id', $customersViewId)->delete();
        }
        $cashierId = DB::table('roles')->where('name', 'Cashier')->value('id');
        $productsViewId = DB::table('permissions')->where('name', 'products.view')->value('id');
        if ($cashierId && $productsViewId) DB::table('role_permissions')->where(['role_id' => $cashierId, 'permission_id' => $productsViewId])->delete();
    }
};
