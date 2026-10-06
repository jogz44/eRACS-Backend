<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lib_expense_sub_items', function (Blueprint $table) {
            $table->id();

            // Belongs to lib_expense_items
            $table->foreignId('expense_item_id')
                ->constrained('lib_expense_items')
                ->cascadeOnDelete();

            $table->string('name');

            $table->integer('order')->default(0);

            $table->timestamps();

            $table->unique([
                'expense_item_id',
                'name'
            ]);

            $table->index('expense_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lib_expense_sub_items');
    }
};
