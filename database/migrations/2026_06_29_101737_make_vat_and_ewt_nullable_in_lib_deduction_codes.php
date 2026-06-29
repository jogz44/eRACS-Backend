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
        Schema::table('lib_deduction_codes', function (Blueprint $table) {
            $table->decimal('vat_percent', 10, 2)
                ->nullable()
                ->change();

            $table->decimal('ewt_percent', 10, 2)
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lib_deduction_codes', function (Blueprint $table) {
            $table->decimal('vat_percent', 10, 2)
                ->nullable(false)
                ->change();

            $table->decimal('ewt_percent', 10, 2)
                ->nullable(false)
                ->change();
        });
    }
};
