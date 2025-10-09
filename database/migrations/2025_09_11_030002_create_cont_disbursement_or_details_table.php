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
        Schema::create('cont_disbursement_or_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cont_disbursement_id');
            $table->date('or_date')->nullable();
            $table->string('or_number')->nullable();
            $table->decimal('or_amount', 15, 2)->nullable();
            $table->string('or_photo')->nullable(); // file path or URL
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('cont_disbursement_id')->references('id')->on('cont_disbursement')->onDelete('cascade');

            $table->index(['cont_disbursement_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cont_disbursement_or_details');
    }
};