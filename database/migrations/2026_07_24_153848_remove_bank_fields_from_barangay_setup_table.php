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
        Schema::table('barangay_setup', function (Blueprint $table) {
        $table->dropForeign(['bank_id']); // if a foreign key exists
        $table->dropColumn([
            'bank_id',
            'account_number',
        ]);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('barangay_setup', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('account_number')->nullable();
        });
    }
};
