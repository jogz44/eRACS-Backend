<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; // Added this import for DB facade

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lib_banks', function (Blueprint $table) {
           $table->id();
            $table->foreignId('barangay_id')->constrained()->onDelete('cascade');
            $table->string('bank_name');
            $table->enum('status', ['available', 'consumed', 'unavailable'])->default('unavailable');
            $table->timestamps();
        });
        

        // Seed initial bank data for all barangays
        $barangayCount = DB::table('barangays')->count();
        $banks = ['BDO', 'Metro Bank', 'BPI'];
        $statuses = ['available', 'unavailable', 'consumed'];
        $bankRows = [];
        for ($i = 1; $i <= $barangayCount; $i++) {
            foreach ($banks as $index => $bank) {
                $bankRows[] = [
                    'barangay_id' => $i,
                    'bank_name' => $bank,
                    'status' => $statuses[$index],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        DB::table('lib_banks')->insert($bankRows);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lib_banks');
    }
};
