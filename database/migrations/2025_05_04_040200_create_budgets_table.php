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
        Schema::create('budgets', function (Blueprint $table) {
        $table->id();
        $table->foreignId('barangay_id')->constrained()->onDelete('cascade');
        $table->foreignId('fiscal_year_id')->constrained('lib_fiscal_years');
        $table->date('start_date');
        $table->date('end_date');
        $table->string('description');
        $table->decimal('original_amount', 12, 2);
        $table->decimal('current_amount', 12, 2);
        $table->decimal('augmentation', 12, 2)->default(0);
        $table->decimal('return_amount', 12, 2)->default(0);
        $table->foreignId('user_id')->constrained('barangay_users')->onDelete('cascade');
        $table->timestamps();
        $table->index(['barangay_id', 'fiscal_year_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
