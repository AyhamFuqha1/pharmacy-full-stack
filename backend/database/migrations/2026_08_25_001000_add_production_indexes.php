<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->index('date');
        });
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->index('date');
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['status', 'entry_date']);
        });
        Schema::table('vouchers', function (Blueprint $table) {
            $table->index(['status', 'source_type', 'source_id']);
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropIndex(['date']));
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropIndex(['date']));
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropIndex(['status', 'entry_date']));
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropIndex(['status', 'source_type', 'source_id']));
        Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex(['user_id', 'created_at']));
    }
};
