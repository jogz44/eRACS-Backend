<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that carry a secondary payee name.
     */
    protected array $tables = ['disbursements', 'cont_disbursement'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            // Guarded so environments where payee2 was added by hand
            // (development databases) can still run this migration.
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'payee2')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('payee2')->nullable()->after('payee');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'payee2')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('payee2');
            });
        }
    }
};
