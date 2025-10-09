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
        Schema::create('annual_budget_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->onDelete('no action');
            $table->foreignId('barangay_id')->constrained()->onDelete('no action');
            
            // Annual budget specific fields
            $table->string('budget_category')->nullable(); // e.g., 'General Fund', 'Special Fund'
            $table->string('source_of_funds')->nullable(); // e.g., 'Internal Revenue Allotment', 'Local Taxes'
            $table->decimal('allocated_amount', 12, 2);
            $table->decimal('utilized_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);
            
            // Planning and approval fields
            $table->text('justification')->nullable();
            $table->text('implementation_plan')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            
            // Status tracking
            $table->enum('allocation_status', ['planned', 'approved', 'active', 'completed', 'cancelled'])->default('planned');
            $table->timestamp('allocation_approved_at')->nullable();
            $table->foreignId('allocation_approved_by')->nullable()->constrained('barangay_users')->onDelete('no action');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['budget_id', 'budget_category']);
            $table->index(['barangay_id', 'allocation_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('annual_budget_details');
    }
};