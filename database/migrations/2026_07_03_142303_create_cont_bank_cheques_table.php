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
        Schema::create('cont_bank_cheques', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cont_disbursement_id')
                ->constrained('cont_disbursement')
                ->cascadeOnDelete();

            $table->foreignId('bank_id')
                ->constrained('lib_banks')
                ->noActionOnDelete();

            $table->string('cheque_number');
            $table->date('cheque_date');

            $table->decimal('amount', 15, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cont_bank_cheques');
    }
};
