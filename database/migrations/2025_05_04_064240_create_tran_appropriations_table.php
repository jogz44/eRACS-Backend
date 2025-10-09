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
        Schema::create('tran_appropriations', function (Blueprint $table) {
            $table->id();
           $table->foreignId('barangay_id')->constrained();
           $table->foreignId('budget_id')->nullable()->constrained();
            
            //$table->foreignId('cont_appropriation_id')->nullable()->constrained('cont_appropriations');
             // Expense hierarchy (class->type->item->sub-item)
            $table->foreignId('expense_class_id')->nullable()->constrained('lib_expense_classes');
            $table->foreignId('expense_type_id')->nullable()->constrained('lib_expense_types');
            $table->foreignId('expense_item_id')->nullable()->constrained('lib_expense_items');
            $table->foreignId('expense_sub_item_id')->nullable()->constrained('lib_expense_items');

            $table->decimal('amount', 12, 2);
            $table->date('transaction_date');
            $table->enum('status', ['draft', 'committed', 'reverted'])->default('draft');
            $table->foreignId('user_id')->constrained('barangay_users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['barangay_id', 'budget_id']);
            $table->index(['expense_class_id', 'expense_type_id', 'expense_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tran_appropriations');
    }
};
