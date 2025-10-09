<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Budget;
use App\Models\AnnualBudgetDetail;
use App\Models\SupplementalBudgetDetail;
use App\Models\Barangay;
use App\Models\LibFiscalYear;
use App\Models\BarangayUser;
use App\BudgetType;
use Faker\Factory as Faker;

class BudgetSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();

        $barangays = Barangay::all();

        foreach ($barangays as $barangay) {
            // Get all fiscal years for this barangay
            $fiscalYears = LibFiscalYear::where('barangay_id', $barangay->id)->get();

            // Find a user for this barangay
            $user = BarangayUser::where('barangay_id', $barangay->id)->first();
            if (!$user) continue;

            foreach ($fiscalYears as $fiscalYear) {
                $year = (int) $fiscalYear->year;

                // Annual Budget
                $annualBudget = Budget::updateOrCreate([
                    'barangay_id'    => $barangay->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'budget_type'    => BudgetType::ANNUAL,
                    'description'    => 'Annual Budget',
                ], [
                    'start_date'      => now()->copy()->setYear($year)->startOfYear(),
                    'end_date'        => now()->copy()->setYear($year)->endOfYear(),
                    'status'          => 'active',
                    'original_amount' => 4000000,
                    'current_amount'  => 4000000,
                    'augmentation'    => 0,
                    'return_amount'   => 0,
                    'user_id'         => $user->id,
                ]);

                // Create Annual Budget Detail
                AnnualBudgetDetail::updateOrCreate([
                    'budget_id' => $annualBudget->id,
                ], [
                    'barangay_id' => $barangay->id,
                    'budget_category' => 'General Fund',
                    'source_of_funds' => 'Internal Revenue Allotment (IRA)',
                    'allocated_amount' => 4000000,
                    'utilized_amount' => 0,
                    'remaining_amount' => 4000000,
                    'justification' => 'Annual budget allocation for general barangay operations and services',
                    'implementation_plan' => 'Distributed across various barangay programs and projects',
                    'planned_start_date' => now()->copy()->setYear($year)->startOfYear(),
                    'planned_end_date' => now()->copy()->setYear($year)->endOfYear(),
                    'allocation_status' => 'active',
                    'allocation_approved_at' => now(),
                    'allocation_approved_by' => $user->id,
                ]);

                // Supplemental Budget (create one for demonstration)
                if ($faker->boolean(30)) { // 30% chance of having supplemental budget
                    $supplementalBudget = Budget::updateOrCreate([
                        'barangay_id'    => $barangay->id,
                        'fiscal_year_id' => $fiscalYear->id,
                        'budget_type'    => BudgetType::SUPPLEMENTAL,
                        'description'    => 'Supplemental Budget - Emergency Fund',
                    ], [
                        'start_date'      => now()->copy()->setYear($year)->addMonths(6)->startOfMonth(),
                        'end_date'        => now()->copy()->setYear($year)->endOfYear(),
                        'status'          => 'active',
                        'effective_date'  => now()->copy()->setYear($year)->addMonths(6)->startOfMonth(),
                        'original_amount' => 500000,
                        'current_amount'  => 500000,
                        'augmentation'    => 0,
                        'return_amount'   => 0,
                        'user_id'         => $user->id,
                    ]);

                    // Create Supplemental Budget Detail
                    SupplementalBudgetDetail::updateOrCreate([
                        'budget_id' => $supplementalBudget->id,
                    ], [
                        'barangay_id' => $barangay->id,
                        'supplement_type' => 'Emergency',
                        'source_of_supplement' => 'Unexpected Revenue',
                        'supplement_amount' => 500000,
                        'utilized_amount' => 0,
                        'remaining_amount' => 500000,
                        'emergency_justification' => 'Emergency fund for unexpected barangay needs',
                        'urgency_level' => 'medium',
                        'impact_assessment' => 'Will help address urgent barangay requirements',
                        'request_date' => now()->copy()->setYear($year)->addMonths(6)->startOfMonth(),
                        'effective_date' => now()->copy()->setYear($year)->addMonths(6)->startOfMonth(),
                        'expiry_date' => now()->copy()->setYear($year)->endOfYear(),
                        'implementation_notes' => 'To be used for emergency situations only',
                        'supplement_status' => 'active',
                        'supplement_approved_at' => now(),
                        'supplement_approved_by' => $user->id,
                    ]);
                }
            }
        }
    }
}
