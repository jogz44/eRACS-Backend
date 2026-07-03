<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cont_deductions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cont_disbursement_id')
                ->constrained('cont_disbursement')
                ->cascadeOnDelete();

            $table->foreignId('deduction_code_id')
                ->nullable()
                ->constrained('lib_deduction_codes')
                ->nullOnDelete();

            $table->string('deduction_type')->nullable();
            $table->string('tax_type')->nullable();
            $table->string('code')->nullable();

            $table->decimal('divisor', 10, 2)->nullable();
            $table->decimal('vat_percent', 10, 2)->default(0);
            $table->decimal('ewt_percent', 10, 2)->default(0);

            $table->string('description')->nullable();

            $table->decimal('gross_vat_inc', 18, 2);
            $table->decimal('gross_vat_exc', 18, 2)->nullable();

            $table->decimal('deduction_amount', 18, 2);
            $table->decimal('net_amount', 18, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cont_deductions');
    }
};
