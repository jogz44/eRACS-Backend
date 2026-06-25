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
        Schema::table('registered_payees', function (Blueprint $table) {
            $table->enum('type', ['Local', 'Foreign'])
                ->default('Local')
                ->after('payee2_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registered_payees', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
