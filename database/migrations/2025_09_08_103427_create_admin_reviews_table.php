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
        Schema::create('admin_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->onDelete('cascade');
            
            // Polymorphic relationship to handle different reviewable types
            $table->morphs('reviewable'); // creates reviewable_type, reviewable_id + index
            
            $table->text('remarks');
            $table->timestamps();

            // Ensure one review per admin per item
            $table->unique(['admin_id', 'reviewable_type', 'reviewable_id'], 'unique_admin_review');
            
            // Extra index for queries involving admin_id and created_at
            $table->index(['admin_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_reviews');
    }
};
