<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursement_or_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disbursement_id');
            $table->date('or_date')->nullable();
            $table->string('or_number')->nullable();
            $table->decimal('or_amount', 15, 2)->nullable();
            $table->decimal('ref_or_amount', 15, 2)->nullable();
            $table->string('or_photo')->nullable(); // file path or URL
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('disbursement_id')->references('id')->on('disbursements')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursement_or_details');
    }
}; 