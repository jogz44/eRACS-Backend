<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('barangay_id');

            // SK or Provincial Aid
            $table->enum('type', ['sk', 'provincial_aid']);

            $table->foreignId('fiscal_year_id')
                  ->constrained('lib_fiscal_years');

            $table->date('date');
            $table->string('dv_number');
            $table->string('cheque_number');
            $table->unsignedBigInteger('bank_id');

            // Payee: SK/Provincial
            $table->string('payee');

            $table->decimal('amount', 15, 2);

            // No charges for SK and Provincial Aid,
            // so no liquidated_amount needed.
            // No limit on amount for both types.

            $table->text('remarks')->nullable();

            $table->enum('status', [
                'Unliquidated',
                'Partial',
                'Liquidated',
                'Void Requested',
                'Voided',
                'Stale',
                'Edit Requested',
            ])->default('Unliquidated');

            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->foreign('barangay_id')
                  ->references('id')->on('barangays')
                  ->onDelete('cascade');

            $table->foreign('bank_id')
                  ->references('id')->on('lib_banks')
                  ->onDelete('NO ACTION');

            $table->foreign('user_id')
                  ->references('id')->on('barangay_users')
                  ->onDelete('set null');

            $table->index(['barangay_id', 'type']);
            $table->index(['barangay_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transfers');
    }
};