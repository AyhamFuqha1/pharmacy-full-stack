<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['name' => 'accounting.periods.manage'],
            ['created_at' => $now, 'updated_at' => $now]
        );

        $permissionId = DB::table('permissions')->where('name', 'accounting.periods.manage')->value('id');
        $roleIds = DB::table('roles')->whereIn('name', ['Administrator', 'Accountant'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'accounting.periods.manage')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
