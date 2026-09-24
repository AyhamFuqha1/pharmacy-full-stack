<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(['name' => 'patients.view'], ['created_at' => now(), 'updated_at' => now()]);
        $roleId = DB::table('roles')->where('name', 'Pharmacist')->value('id');
        $permissionIds = DB::table('permissions')->whereIn('name', ['patients.view', 'products.view'])->pluck('id');
        foreach ($permissionIds as $permissionId) {
            if ($roleId) DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'patients.view')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
