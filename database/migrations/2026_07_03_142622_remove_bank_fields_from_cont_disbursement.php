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
        Schema::table('cont_disbursement', function (Blueprint $table) {
            $table->dropForeign(['bank_id']);

            $table->dropColumn([
                'bank_id',
                'cheque_number',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cont_disbursement', function (Blueprint $table) {
            //
        });
    }
};
