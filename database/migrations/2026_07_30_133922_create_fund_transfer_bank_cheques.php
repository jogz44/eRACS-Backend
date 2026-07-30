<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_transfer_bank_cheques', function (Blueprint $table) {

            $table->id();

            $table->foreignId('fund_transfer_id')
                ->constrained('fund_transfers')
                ->cascadeOnDelete();

            $table->foreignId('bank_id')
                ->constrained('lib_banks');

            $table->string('cheque_number');

            $table->date('cheque_date')->nullable();

            $table->enum('bank_status', ['online', 'offline']);

            $table->decimal('amount', 15, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transfer_bank_cheques');
    }
};
