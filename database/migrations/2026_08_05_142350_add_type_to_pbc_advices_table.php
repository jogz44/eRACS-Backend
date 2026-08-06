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
        Schema::table('pbc_advices', function (Blueprint $table) {
            $table->enum('type', ['regular', 'continuing'])
                ->default('regular')
                ->after('barangay_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pbc_advices', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
