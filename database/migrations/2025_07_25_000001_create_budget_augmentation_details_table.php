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
        Schema::create('budget_augmentation_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_augmentation_id');
            $table->unsignedBigInteger('from_appropriation_id'); // Reference to source appropriation
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('to_appropriation_id'); // Reference to destination appropriation
            $table->text('particulars');
            $table->timestamps();

            $table->foreign('budget_augmentation_id')->references('id')->on('budget_augmentations')->onDelete('cascade');
            $table->foreign('from_appropriation_id')->references('id')->on('tran_appropriations')->onDelete('no action');
            $table->foreign('to_appropriation_id')->references('id')->on('tran_appropriations')->onDelete('no action');

            $table->index(['budget_augmentation_id']);
            $table->index(['from_appropriation_id']);
            $table->index(['to_appropriation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_augmentation_details');
    }
}; 