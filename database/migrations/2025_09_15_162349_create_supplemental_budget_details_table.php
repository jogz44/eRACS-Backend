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
        Schema::create('supplemental_budget_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->onDelete('no action');
            $table->foreignId('barangay_id')->constrained()->onDelete('no action');
            
            // Supplemental budget specific fields
            $table->string('supplement_type')->nullable(); // e.g., 'Emergency', 'Additional Allocation', 'Reallocation'
            $table->string('source_of_supplement')->nullable(); // e.g., 'Unexpected Revenue', 'Savings', 'Emergency Fund'
            $table->decimal('supplement_amount', 12, 2);
            $table->decimal('utilized_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);
            
            // Emergency/Urgency fields
            $table->text('emergency_justification')->nullable();
            $table->enum('urgency_level', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->text('impact_assessment')->nullable();
            
            // Approval and implementation
            $table->date('request_date');
            $table->date('effective_date');
            $table->date('expiry_date')->nullable(); // When the supplement expires
            $table->text('implementation_notes')->nullable();
            
            // Status tracking
            $table->enum('supplement_status', ['requested', 'under_review', 'approved', 'active', 'expired', 'cancelled'])->default('requested');
            $table->timestamp('supplement_approved_at')->nullable();
            $table->foreignId('supplement_approved_by')->nullable()->constrained('barangay_users')->onDelete('no action');
            
            // Reference to original budget if this is a reallocation
            $table->foreignId('original_budget_id')->nullable()->constrained('budgets')->onDelete('no action');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['budget_id', 'supplement_type']);
            $table->index(['barangay_id', 'supplement_status']);
            $table->index(['urgency_level', 'supplement_status']);
            $table->index(['effective_date', 'expiry_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplemental_budget_details');
    }
};