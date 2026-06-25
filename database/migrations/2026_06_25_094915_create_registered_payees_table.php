<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registered_payees', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('barangay_id');

            $table->string('firstname')->nullable();
            $table->string('middlename')->nullable();
            $table->string('lastname')->nullable();

            $table->string('payee_name');
            $table->string('payee2_name')->nullable();

            $table->text('address')->nullable();
            $table->string('zip_code', 20)->nullable();

            $table->string('taxpayer_type')->nullable();
            $table->string('tin_number', 30)->nullable();

            $table->text('description')->nullable();

            $table->timestamps();

            $table->foreign('barangay_id')
                ->references('id')
                ->on('barangays')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registered_payees');
    }
};
