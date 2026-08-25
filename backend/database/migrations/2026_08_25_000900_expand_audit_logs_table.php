<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('model_name')->nullable()->after('table_name');
            $table->string('action_type')->nullable()->after('action');
            $table->json('old_values')->nullable()->after('old_data');
            $table->json('new_values')->nullable()->after('new_data');
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['model_name', 'action_type', 'old_values', 'new_values']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
