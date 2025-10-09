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
            Schema::create('barangay_users', function (Blueprint $table) {
                $table->id();
        $table->string('first_name');
        $table->string('middle_name')->nullable();
        $table->string('last_name');
        $table->foreignId('barangay_id')->constrained('barangays'); 
        $table->foreignId('position_id')->constrained('barangay_positions');
        $table->string('suffix')->nullable();
        $table->string('photo_path')->nullable();
        $table->string('email')->unique();
        $table->string('username')->unique();
        $table->string('password');
        $table->enum('role', ['admin', 'barangay_user'])->default('barangay_user');
        $table->boolean('is_approved')->default(false);
        // JSON field for simple permissions (view, add, edit, delete, print)
        $table->json('permissions')->nullable();
        $table->rememberToken();
        $table->timestamps();

        $table->index('barangay_id');

            });
        }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('barangay_users');
    }
};
