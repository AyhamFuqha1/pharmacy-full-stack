<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customers', 'deleted_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->timestamp('deleted_at')->nullable()->index();
            });
        }

        if (Schema::hasColumn('customers', 'delete_at')) {
            DB::table('customers')
                ->whereNotNull('delete_at')
                ->whereNull('deleted_at')
                ->update(['deleted_at' => DB::raw('delete_at')]);
        }

        if (!Schema::hasColumn('providers', 'deleted_at')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->timestamp('deleted_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
