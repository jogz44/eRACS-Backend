<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tran_expense_details', function (Blueprint $table) {
            $table->date('cheque_date')->nullable()->after('cheque_number');
        });
    }

    public function down()
    {
        Schema::table('tran_expense_details', function (Blueprint $table) {
            $table->dropColumn('cheque_date');
        });
    }
};
