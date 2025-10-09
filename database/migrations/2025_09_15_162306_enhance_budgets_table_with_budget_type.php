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
        Schema::table('budgets', function (Blueprint $table) {
            // Add budget type field
            $table->enum('budget_type', ['annual', 'supplemental'])->default('annual')->after('description');
            
            // Add status field for better budget management
            $table->enum('status', ['draft', 'approved', 'active', 'closed'])->default('draft')->after('budget_type');
            
            // Add approval fields
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->constrained('barangay_users')->onDelete('no action')->after('approved_at');
            
            // Add effective date for supplemental budgets
            $table->date('effective_date')->nullable()->after('approved_at');
            
            // Add index for better performance
            $table->index(['barangay_id', 'fiscal_year_id', 'budget_type'], 'idx_budget_type_lookup');
            $table->index(['budget_type', 'status'], 'idx_budget_type_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex('idx_budget_type_lookup');
            $table->dropIndex('idx_budget_type_status');
            $table->dropColumn([
                'budget_type',
                'status', 
                'approved_at',
                'approved_by',
                'effective_date'
            ]);
        });
    }
};