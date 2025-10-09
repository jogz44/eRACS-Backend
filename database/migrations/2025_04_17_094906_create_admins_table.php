<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('admins', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->string('name')->nullable();
        $table->enum('role', ['super_admin', 'coa'])->default('super_admin');
        $table->rememberToken();
        $table->timestamps();
    });

    // Insert super admin account
    DB::table('admins')->insert([
        'email' => 'admin',
        'password' => Hash::make('admin123'),
        'name' => 'Super Administrator',
        'role' => 'super_admin',
        'created_at' => now(),
        'updated_at' => now()
    ]);

    // Insert COA account
    DB::table('admins')->insert([
        'email' => 'coa',
        'password' => Hash::make('coa123'),
        'name' => 'COA Officer',
        'role' => 'coa',
        'created_at' => now(),
        'updated_at' => now()
    ]);
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
