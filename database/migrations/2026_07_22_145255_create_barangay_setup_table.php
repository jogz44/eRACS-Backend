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
        Schema::create('barangay_setup', function (Blueprint $table) {

            $table->id();

            // Barangay
            $table->foreignId('barangay_id')
                ->constrained('barangays');

            // Registered User
            $table->foreignId('registered_user_id')
                ->constrained('barangay_users');

            // Barangay Position
            $table->foreignId('barangay_position_id')
                ->constrained('barangay_positions');

            // Bank
            $table->foreignId('bank_id')
                ->constrained('lib_banks');

            $table->string('account_number');

            $table->string('noted_by');

            $table->string('certified_by');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('barangay_setup');
    }
};
