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
        Schema::create('barangay_bank_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_setup_id')
                ->constrained('barangay_setup')
                ->cascadeOnDelete();

            $table->foreignId('bank_id')
                ->constrained('lib_banks');

            $table->string('account_number', 100);

            $table->boolean('is_default')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('barangay_bank_accounts');
    }
};
