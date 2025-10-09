<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TranExpenseDetail;
use App\Models\Disbursement;
use App\Models\TranAppropriation;
use App\Models\Barangay;

class TranExpenseDetailSeeder extends Seeder
{
    public function run()
    {
        // Generate expense details for all barangays that have both disbursements and appropriations
        $expenseDetails = [];

        foreach (Barangay::all() as $barangay) {
            $disbursements = Disbursement::where('barangay_id', $barangay->id)->get();
            if ($disbursements->isEmpty()) {
                $this->command->warn("No disbursements found for Barangay {$barangay->name}. Skipping.");
                continue;
            }

            $appropriations = TranAppropriation::where('barangay_id', $barangay->id)->get();
            if ($appropriations->isEmpty()) {
                $this->command->warn("No appropriations found for Barangay {$barangay->name}. Skipping.");
                continue;
            }

            foreach ($disbursements as $disbursement) {
                // Create expense details that match the disbursement amount
                // Each disbursement has a specific dv_amount, so we'll split it among appropriations
                $remainingAmount = $disbursement->dv_amount;
                $numExpenses = rand(1, 3); // 1-3 expense details per disbursement
                $usedAppropriations = [];

                for ($i = 0; $i < $numExpenses && $remainingAmount > 0; $i++) {
                    // Get a random appropriation that hasn't been used for this disbursement
                    $availableAppropriations = $appropriations->whereNotIn('id', $usedAppropriations);
                    if ($availableAppropriations->isEmpty()) {
                        break;
                    }

                    $appropriation = $availableAppropriations->random();
                    $usedAppropriations[] = $appropriation->id;

                    // Calculate amount for this expense detail
                    if ($i === $numExpenses - 1) {
                        // Last expense detail gets the remaining amount
                        $amount = $remainingAmount;
                    } else {
                        // Distribute amount evenly among expense details
                        $amount = round($remainingAmount / ($numExpenses - $i), 2);
                    }

                    if ($amount > 0) {
                        $expenseDetails[] = [
                            'disbursement_id' => $disbursement->id,
                            'appropriation_id' => $appropriation->id,
                            'amount' => $amount,
                            'particulars' => 'Expense detail ' . ($i + 1) . ' for ' . $disbursement->dv_number . ' - ' . ($appropriation->expense_item_id ? 'Item level' : 'Type level'),
                            'created_at' => $disbursement->created_at,
                            'updated_at' => $disbursement->updated_at,
                        ];

                        $remainingAmount -= $amount;
                    }
                }
            }
        }

        // Insert all expense details
        if (!empty($expenseDetails)) {
            TranExpenseDetail::insert($expenseDetails);
        }
    }
}
