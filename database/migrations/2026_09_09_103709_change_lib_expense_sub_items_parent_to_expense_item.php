<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lib_expense_sub_items', function (Blueprint $table) {
            // Remove the old relationship
            $table->dropForeign(['sub_type_id']);
            $table->dropUnique([
                'sub_type_id',
                'name'
            ]);
            $table->dropIndex([
                'sub_type_id'
            ]);

            $table->dropColumn('sub_type_id');

            // New relationship:
            // lib_expense_sub_items → lib_expense_items
            $table->foreignId('expense_item_id')
                ->after('id')
                ->constrained('lib_expense_items')
                ->cascadeOnDelete();

            $table->unique([
                'expense_item_id',
                'name'
            ]);

            $table->index('expense_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('lib_expense_sub_items', function (Blueprint $table) {
            $table->dropForeign(['expense_item_id']);

            $table->dropUnique([
                'expense_item_id',
                'name'
            ]);

            $table->dropIndex([
                'expense_item_id'
            ]);

            $table->dropColumn('expense_item_id');

            // Restore old relationship
            $table->foreignId('sub_type_id')
                ->after('id')
                ->constrained('lib_expense_sub_types')
                ->cascadeOnDelete();

            $table->unique([
                'sub_type_id',
                'name'
            ]);

            $table->index('sub_type_id');
        });
    }
};
