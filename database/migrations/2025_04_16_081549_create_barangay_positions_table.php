<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('barangay_positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Insert the default barangay positions
        DB::table('barangay_positions')->insert([
            ['name' => 'Barangay Captain', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'SK Chairperson', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'SK Treasurer', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Barangay Treasurer', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('barangay_positions');
    }
};
