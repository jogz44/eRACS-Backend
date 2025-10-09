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
        Schema::create('budget_augmentations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_id');
            $table->unsignedBigInteger('budget_id');
            $table->string('ref_number')->unique();
            $table->date('augmentation_date');
            $table->decimal('total_amount', 15, 2);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('barangay_id')->references('id')->on('barangays')->onDelete('no action');
            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('no action');
            $table->foreign('user_id')->references('id')->on('barangay_users')->onDelete('no action');

            $table->index(['barangay_id', 'budget_id']);
            $table->index(['augmentation_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_augmentations');
    }
}; 