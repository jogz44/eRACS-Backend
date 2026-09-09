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

            $table->foreignId('sub_type_id')
                ->constrained('lib_expense_sub_types')
                ->cascadeOnDelete();

            $table->string('name');

            $table->integer('order')->default(0);

            $table->timestamps();

            $table->unique([
                'sub_type_id',
                'name'
            ]);

            $table->index('sub_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lib_expense_sub_items');
    }
};
