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
        Schema::create('cont_appropriations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained();
            $table->foreignId('fiscal_year_id')->constrained('lib_fiscal_years');
            $table->string('description');
            $table->string('expense_class');
            $table->decimal('appropriation_amount', 15, 2);
            $table->decimal('unappropriated_amount', 15, 2);
            $table->date('continued_date');
            $table->enum('status', ['draft', 'committed', 'reverted'])->default('draft');
            $table->foreignId('user_id')->constrained('barangay_users');
            $table->timestamps();
            
            $table->index(['barangay_id', 'fiscal_year_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cont_appropriations');
    }
};
