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
        Schema::create('lib_deduction_codes', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();

            $table->string('label');

            $table->string('deduction_type');

            $table->string('tax_type')->nullable();

            $table->integer('divisor')->default(1);

            $table->decimal('vat_percent',10,2)->default(0);

            $table->decimal('ewt_percent',10,2)->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lib_deduction_codes');
    }
};
