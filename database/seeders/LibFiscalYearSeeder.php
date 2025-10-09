<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LibFiscalYear;
use App\Models\Barangay;

class LibFiscalYearSeeder extends Seeder
{
    public function run()
    {
        $years = [2023, 2024];

        foreach (Barangay::all() as $barangay) {
            // Ensure all years exist for the barangay
            foreach ($years as $year) {
                LibFiscalYear::updateOrCreate(
                    [
                        'barangay_id' => $barangay->id,
                        'year' => $year,
                        'is_active' => false, // set below
                    ]
                );
            }

            // Mark the latest year as active for this barangay
            $latest = LibFiscalYear::where('barangay_id', $barangay->id)
                ->orderByDesc('year')
                ->first();

            if ($latest) {
                LibFiscalYear::where('barangay_id', $barangay->id)->update(['is_active' => false]);
                $latest->is_active = true;
                $latest->save();
            }
        }
    }
}
