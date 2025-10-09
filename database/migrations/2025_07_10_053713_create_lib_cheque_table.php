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
        Schema::create('lib_cheque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booklet_id')->constrained('lib_booklet')->onDelete('cascade');
            $table->string('cheque_number');
            $table->enum('status', ['unused', 'used', 'void','cancelled'])->default('unused');
            $table->unsignedBigInteger('disbursement_id')->nullable();
            $table->timestamps();
            
            //$table->foreign('disbursement_id')->references('id')->on('disbursements')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lib_cheque');
    }
};
