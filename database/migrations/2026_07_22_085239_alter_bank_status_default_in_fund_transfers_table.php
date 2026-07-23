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
        // Drop the existing default constraint
        DB::statement("
            DECLARE @ConstraintName NVARCHAR(200);

            SELECT @ConstraintName = dc.name
            FROM sys.default_constraints dc
            INNER JOIN sys.columns c
                ON dc.parent_object_id = c.object_id
                AND dc.parent_column_id = c.column_id
            WHERE OBJECT_NAME(dc.parent_object_id) = 'fund_transfers'
              AND c.name = 'bank_status';

            IF @ConstraintName IS NOT NULL
                EXEC('ALTER TABLE fund_transfers DROP CONSTRAINT ' + @ConstraintName);
        ");

        // Change existing Pending values to Offline
        DB::table('fund_transfers')
            ->where('bank_status', 'Pending')
            ->update([
                'bank_status' => 'Offline'
            ]);

        // Add the new default
        DB::statement("
            ALTER TABLE fund_transfers
            ADD CONSTRAINT DF_fund_transfers_bank_status
            DEFAULT 'Offline' FOR bank_status
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE fund_transfers
            DROP CONSTRAINT DF_fund_transfers_bank_status
        ");

        DB::statement("
            ALTER TABLE fund_transfers
            ADD CONSTRAINT DF_fund_transfers_bank_status
            DEFAULT 'Pending' FOR bank_status
        ");
    }
};
