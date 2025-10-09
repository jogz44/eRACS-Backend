<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Barangay;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lib_fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained()->onDelete('cascade');
            $table->string('year', 4); // e.g. "2024"
            $table->boolean('is_active')->default(false);
            $table->timestamps();

             // Ensure each barangay has unique year entries
            $table->unique(['barangay_id', 'year']);
        });

        foreach (Barangay::all() as $barangay) {
            DB::table('lib_fiscal_years')->insert(['barangay_id' => $barangay->id, 'year' => now()->year, 'is_active' => false,]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lib_fiscal_years');
    }
};
