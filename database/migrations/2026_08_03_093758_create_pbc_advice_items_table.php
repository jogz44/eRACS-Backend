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
        Schema::create('pbc_advice_items', function (Blueprint $table) {

            $table->id();

            $table->foreignId('pbc_advice_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('disbursement_id')
                ->constrained();

            $table->foreignId('bank_cheque_id')
                ->constrained('bank_cheques');

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pbc_advice_items');
    }
};
