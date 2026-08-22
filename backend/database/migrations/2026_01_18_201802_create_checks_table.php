<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->string('check_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->enum('status', ['pending', 'cleared', 'bounced'])->default('pending');
            $table->enum('direction', ['in', 'out']);
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp("created_at");
            $table->index(['reference_type', 'reference_id']);
            $table->index('due_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
