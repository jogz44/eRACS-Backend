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
        Schema::create('fund_categories', function (Blueprint $table) {
            $table->id('f_id');

            $table->string('f_code', 20)->unique();
            $table->string('f_description', 255);

            $table->enum('status', [
                'Available',
                'Inactive'
            ])->default('Available');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fund_categories');
    }
};
