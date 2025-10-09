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
        Schema::create('lib_booklet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('lib_banks');
            $table->string('booklet_numb');
            $table->string('starting_cheque_numb');
            $table->string('ending_cheque_numb');
            $table->enum('status', ['unused', 'consumed', 'not all consumed'])->default('unused');
            $table->timestamps();
        });



    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lib_booklet');
    }
};
