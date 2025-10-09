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
        Schema::create('cont_tran_expense_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cont_disbursement_id');
            $table->unsignedBigInteger('cont_appro_account_id'); // Reference to continuing appropriation account
            $table->decimal('amount', 15, 2); // Amount allocated to this expense
            $table->text('particulars')->nullable(); // Description/particulars for this expense
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('cont_disbursement_id')->references('id')->on('cont_disbursement')->onDelete('cascade');
            $table->foreign('cont_appro_account_id')->references('id')->on('cont_appro_accounts')->onDelete('cascade');

            // Indexes for better performance
            $table->index(['cont_disbursement_id']);
            $table->index(['cont_appro_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cont_tran_expense_detail');
    }
};