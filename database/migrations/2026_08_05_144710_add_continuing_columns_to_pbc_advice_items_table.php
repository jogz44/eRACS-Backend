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
        Schema::table('pbc_advice_items', function (Blueprint $table) {

            $table->unsignedBigInteger('cont_disbursement_id')->nullable()->after('disbursement_id');

            $table->unsignedBigInteger('cont_bank_cheque_id')->nullable()->after('bank_cheque_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pbc_advice_items', function (Blueprint $table) {

            $table->dropColumn([
                'cont_disbursement_id',
                'cont_bank_cheque_id'
            ]);

        });
    }
};
