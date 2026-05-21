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
        Schema::create('bir_remittances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('barangay_id');
            $table->date('date');
            $table->string('dv_number');
            $table->string('ref_dv_number')->nullable();
            $table->string('cheque_number');
            $table->unsignedBigInteger('bank_id');
            $table->string('payee');
            $table->decimal('dv_amount', 15, 2);
            $table->decimal('liquidated_amount', 15, 2)->nullable();
            $table->enum('status', ['Unliquidated', 'Partial', 'Liquidated', 'Void Requested', 'Voided', 'Stale', 'Edit Requested'])->default('Unliquidated');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('liquidated_at')->nullable();
            $table->text('remarks')->nullable();
            $table->text('rejection_remarks')->nullable();
            $table->timestamps();
    
            $table->foreign('user_id')->references('id')->on('barangay_users')->onDelete('set null');
            $table->foreign('barangay_id')->references('id')->on('barangays')->onDelete('cascade');
            $table->foreign('bank_id')->references('id')->on('lib_banks')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bir_remittances');
    }
};
