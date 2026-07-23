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
        Schema::table('barangay_setup', function (Blueprint $table) {

            $table->unsignedBigInteger('noted_by_position_id')
                ->nullable()
                ->after('noted_by');

            $table->unsignedBigInteger('certified_by_position_id')
                ->nullable()
                ->after('certified_by');

            $table->foreign('noted_by_position_id')
                ->references('id')
                ->on('barangay_positions');

            $table->foreign('certified_by_position_id')
                ->references('id')
                ->on('barangay_positions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('barangay_setup', function (Blueprint $table) {

            $table->dropForeign(['noted_by_position_id']);
            $table->dropForeign(['certified_by_position_id']);

            $table->dropColumn([
                'noted_by_position_id',
                'certified_by_position_id'
            ]);
        });
    }
};
