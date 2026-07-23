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
        Schema::create('bank_export_files', function (Blueprint $table) {

            $table->id();

            // Link to the disbursement
            $table->foreignId('disbursement_id')
                ->constrained('disbursements')
                ->cascadeOnDelete();

            // Link to barangay setup
            $table->foreignId('barangay_setup_id')
                ->constrained('barangay_setup');

            // Optional user who generated it
            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('barangay_users');

            // Generated filename
            $table->string('filename');

            // File location
            $table->string('filepath')->nullable();

            // Has the txt already been exported?
            $table->boolean('is_exported')->default(false);

            // Export date
            $table->timestamp('exported_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_export_files');
    }
};
