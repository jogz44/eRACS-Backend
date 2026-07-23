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
        Schema::table('bir_remittances', function (Blueprint $table) {
            $table->string('bank_status', 20)
                  ->default('Offline')
                  ->after('bank_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bir_remittances', function (Blueprint $table) {
            $table->dropColumn('bank_status');
        });
    }
};
