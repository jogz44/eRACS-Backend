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
        Schema::create('tran_expense_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disbursement_id');
            $table->unsignedBigInteger('appropriation_id'); // Reference to appropriation/expense item
            $table->decimal('amount', 15, 2); // Amount allocated to this expense
            $table->text('particulars')->nullable(); // Description/particulars for this expense
            $table->timestamps();

            // Foreign key constraints  
            $table->foreign('disbursement_id')->references('id')->on('disbursements')->onDelete('cascade');
            $table->foreign('appropriation_id')->references('id')->on('tran_appropriations')->onDelete('cascade');
            $table->foreignId('bank_id')->nullable()->after('particulars')->constrained('lib_banks');
    $table->string('cheque_number')->nullable()->after('bank_id');

            // Indexes for better performance
            $table->index(['disbursement_id']);
            $table->index(['appropriation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tran_expense_details');
    }
};
