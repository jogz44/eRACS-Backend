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
        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Seed the barangays immediately after creation (no 'code' field)
        DB::table('barangays')->insert([
            ['name' => 'Apokon', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bincungan', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Busaon', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Canocotan', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Cuambogan', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'La Filipina', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Liboganon', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Madaum', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Magdum', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Mankilam', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'New Balamban', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Nueva Fuerza', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pagsabangan', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pandapan', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Magugpo Poblacion', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'San Agustin', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'San Isidro', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'San Miguel', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Visayan Village', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Magugpo East', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Magugpo North', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Magugpo South', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Magugpo West', 'created_at' => now(), 'updated_at' => now()],
        ]);


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('barangays');
    }
};
