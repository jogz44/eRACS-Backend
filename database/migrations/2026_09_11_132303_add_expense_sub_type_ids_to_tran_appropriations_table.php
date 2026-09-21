<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tran_appropriations', function (Blueprint $table) {
            $table->foreignId('expense_sub_type_id')
                ->nullable()
                ->after('expense_sub_item_id')
                ->constrained('lib_expense_sub_types');

            $table->foreignId('expense_sub_sub_type_id')
                ->nullable()
                ->after('expense_sub_type_id')
                ->constrained('lib_expense_sub_sub_types');
        });
    }

    public function down(): void
    {
        Schema::table('tran_appropriations', function (Blueprint $table) {
            $table->dropForeign(['expense_sub_sub_type_id']);
            $table->dropForeign(['expense_sub_type_id']);

            $table->dropColumn([
                'expense_sub_type_id',
                'expense_sub_sub_type_id',
            ]);
        });
    }
};
