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
        Schema::create('deductions', function (Blueprint $table) {
            $table->id();

            // Related disbursement
            $table->foreignId('disbursement_id')
                ->nullable()
                ->constrained('disbursements')
                ->cascadeOnDelete();

            // businessTax / EWT / others
            $table->string('deduction_type')->nullable();

            // percentage / services / VAT / VAT Exempt / Professional Services / No Tax
            $table->string('tax_type')->nullable();

            // WV010 / WI157 / OTHER
            $table->string('code')->nullable();

            $table->integer('divisor')->default(1);

            $table->decimal('vat_percent', 10, 2)->default(0);
            $table->decimal('ewt_percent', 10, 2)->default(0);

            $table->string('description')->nullable();

            $table->decimal('gross_vat_inc', 18, 2)->default(0);
            $table->decimal('gross_vat_exc', 18, 2)->default(0);

             /*
            Computed OR manual
            */
            $table->decimal('deduction_amount', 18, 2)
                  ->nullable();

            $table->timestamps();

            $table->foreign('disbursement_id')
                ->references('id')
                ->on('disbursements')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deductions');
    }
};
