<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('print_identity_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk', 30);
            $table->string('storage_key');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('document_print_identity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 80);
            $table->unsignedBigInteger('document_id');
            $table->json('identity');
            $table->timestamp('captured_at');
            $table->unique(['document_type', 'document_id'], 'document_print_identity_unique');
        });
        DB::table('permissions')->updateOrInsert(
            ['name' => 'print_identity.manage'],
            ['description' => 'Manage the business identity used on printed documents', 'created_at' => now(), 'updated_at' => now()]
        );
        $permission = DB::table('permissions')->where('name', 'print_identity.manage')->value('id');
        $administrator = DB::table('roles')->where('name', 'Administrator')->value('id');
        if ($permission && $administrator) DB::table('role_permissions')->insertOrIgnore(['role_id' => $administrator, 'permission_id' => $permission]);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_print_identity_snapshots');
        Schema::dropIfExists('print_identity_assets');
        $permission = DB::table('permissions')->where('name', 'print_identity.manage')->value('id');
        if ($permission) {
            DB::table('role_permissions')->where('permission_id', $permission)->delete();
            DB::table('permissions')->where('id', $permission)->delete();
        }
    }
};
