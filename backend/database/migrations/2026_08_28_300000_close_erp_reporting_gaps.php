<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $t) {
            $t->string('disk')->nullable();
            $t->string('storage_reference')->nullable();
            $t->string('checksum',64)->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamp('parsed_at')->nullable();
            $t->timestamp('previewed_at')->nullable();
            $t->text('error_summary')->nullable();
            $t->index(['domain','status','created_at']);
        });
        Schema::create('application_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
            $t->string('value_type')->default('string');
            $t->text('description')->nullable();
            $t->timestamps();
        });
        DB::table('application_settings')->insert([
            'key'=>'cashier_shift_discrepancy_threshold','value'=>'5.00','value_type'=>'decimal',
            'description'=>'Absolute closed-shift difference that creates an actionable alert.',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('application_settings');
        Schema::table('import_jobs', fn(Blueprint $t)=>$t->dropColumn(['disk','storage_reference','checksum','file_size','uploaded_at','parsed_at','previewed_at','error_summary']));
    }
};
