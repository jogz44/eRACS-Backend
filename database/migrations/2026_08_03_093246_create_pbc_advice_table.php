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
        Schema::create('pbc_advices', function (Blueprint $table) {

            $table->id();

            $table->foreignId('barangay_id')->constrained();

            $table->foreignId('bank_id')->nullable()->constrained('lib_banks');

            $table->string('advice_no')->unique();

            $table->date('advice_date');

            $table->date('from_date');

            $table->date('to_date');

            $table->integer('voucher_count');

            $table->decimal('total_amount',15,2);

            $table->foreignId('created_by')->nullable()->constrained('barangay_users');

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pbc_advice');
    }
};
