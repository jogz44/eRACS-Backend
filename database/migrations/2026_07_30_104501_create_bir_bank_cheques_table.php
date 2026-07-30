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
        Schema::create('bir_bank_cheques', function (Blueprint $table) {

            $table->id();

            $table->foreignId('bir_remittance_id')
                ->constrained('bir_remittances')
                ->cascadeOnDelete();

            $table->foreignId('bank_id')
                ->constrained('lib_banks');

            $table->string('cheque_number');

            $table->date('cheque_date')->nullable();

            $table->enum('bank_status', ['online','offline']);

            $table->decimal('amount',15,2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bir_bank_cheques');
    }
};
