<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->string('cheque_number')->nullable()->change();
            $table->date('cheque_date')->nullable()->change();
            $table->foreignId('bank_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->string('cheque_number')->nullable(false)->change();
            $table->date('cheque_date')->nullable(false)->change();
            $table->foreignId('bank_id')->nullable(false)->change();
        });
    }
};
