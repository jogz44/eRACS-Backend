<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BarangayUserSeeder::class,
            LibFiscalYearSeeder::class,
            LibExpenseClassAndTypeSeeder::class,
            BudgetSeeder::class,

            TranAppropriationSeeder::class,
            //BudgetAugmentationSeeder::class,
            BookletAndChequeSeeder::class,
            DisbursementSeeder::class
        ]);
    }
}
