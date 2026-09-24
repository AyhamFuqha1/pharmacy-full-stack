<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_vouchers', function (Blueprint $table) {
            $table->string('payment_method')->default('cash')->after('amount')->index();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->string('payment_method')->default('cash')->after('amount')->index();
        });
        Schema::table('checks', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('reference_id')->constrained('customers')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->after('customer_id')->constrained('providers')->nullOnDelete();
            $table->foreignId('receipt_voucher_id')->nullable()->after('provider_id')->constrained('receipt_vouchers')->nullOnDelete();
            $table->foreignId('payment_voucher_id')->nullable()->after('receipt_voucher_id')->constrained('payment_vouchers')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        if (DB::table('accounts')->exists()) {
            $assetParent = DB::table('accounts')->where('code', '1000')->value('id');
            $liabilityParent = DB::table('accounts')->where('code', '2000')->value('id');
            foreach ([
                ['code' => '1500', 'name' => 'Cheques Receivable', 'parent_id' => $assetParent, 'account_type' => 'asset', 'normal_balance' => 'debit'],
                ['code' => '2200', 'name' => 'Cheques Payable', 'parent_id' => $liabilityParent, 'account_type' => 'liability', 'normal_balance' => 'credit'],
                ['code' => '3000', 'name' => 'Equity', 'parent_id' => null, 'account_type' => 'equity', 'normal_balance' => 'credit'],
            ] as $account) {
                DB::table('accounts')->updateOrInsert(['code' => $account['code']], array_merge($account, [
                    'active' => true, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::table('checks', fn (Blueprint $table) => $table->dropConstrainedForeignId('payment_voucher_id'));
        Schema::table('checks', fn (Blueprint $table) => $table->dropConstrainedForeignId('receipt_voucher_id'));
        Schema::table('checks', fn (Blueprint $table) => $table->dropConstrainedForeignId('provider_id'));
        Schema::table('checks', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::table('checks', fn (Blueprint $table) => $table->dropColumn(['cleared_at', 'bounced_at', 'updated_at']));
        Schema::table('payment_vouchers', fn (Blueprint $table) => $table->dropColumn('payment_method'));
        Schema::table('receipt_vouchers', fn (Blueprint $table) => $table->dropColumn('payment_method'));
    }
};
