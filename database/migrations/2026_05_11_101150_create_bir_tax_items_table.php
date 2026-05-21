<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bir_tax_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('barangay_id');

            // The original disbursement this tax was computed from.
            // Other system fetches disbursements via API key, computes
            // the tax, then sends it back referencing this disbursement.
            $table->unsignedBigInteger('disbursement_id');

            // Tax amount sent back by the other system
            $table->decimal('tax_amount', 15, 2);

            // pending  → tax received, not yet remitted to BIR
            // remitted → included in a BIR remittance DV
            $table->enum('status', ['pending', 'remitted'])->default('pending');
    
            // Set when user clicks "Create Remittance" —
            // points to the DV in bir_remittances that covered this tax item.
            // Nullable because it's only filled after remittance is created.
            $table->unsignedBigInteger('bir_remittance_id')->nullable();

            // For audit: which external system/api key submitted this
            $table->string('source_reference')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('barangay_id')
                  ->references('id')->on('barangays')
                  ->onDelete('cascade');

$table->foreign('disbursement_id')
      ->references('id')->on('disbursements');

            $table->foreign('bir_remittance_id')
                  ->references('id')->on('bir_remittances');

            // Indexes
            $table->index(['barangay_id', 'status']);
            $table->index(['disbursement_id']);
            $table->index(['bir_remittance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bir_tax_items');
    }
};