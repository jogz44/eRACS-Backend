<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BudgetAugmentation;
use App\Models\BudgetAugmentationDetail;
use App\Models\Budget;
use App\Models\LibExpenseClass;
use App\Models\LibExpenseType;
use App\Models\LibExpenseItem;
use App\Models\BarangayUser;
use App\Models\Barangay;
use App\Models\LibFiscalYear;
use App\Models\TranAppropriation;
use Carbon\Carbon;

class BudgetAugmentationSeeder extends Seeder
{
    public function run()
    {
        $now = now();
        $barangays = Barangay::all();
        
        // Create only 2 augmentation entries total
        $augmentationCount = 0;
        $maxAugmentations = 2;
        
        foreach ($barangays as $bIndex => $barangay) {
            if ($augmentationCount >= $maxAugmentations) break;
            
            $budgets = Budget::where('barangay_id', $barangay->id)->get();
            $user = BarangayUser::where('barangay_id', $barangay->id)->first();
            
            if ($budgets->count() < 2 || !$user) continue; // Need at least 2 budgets for cross-budget transfer
            
            // Get all appropriations for this barangay
            $allAppropriations = TranAppropriation::where('barangay_id', $barangay->id)
                ->where('status', 'committed')
                ->where('amount', '>', 1000)
                ->get();
            
            if ($allAppropriations->count() < 2) continue; // Need at least 2 appropriations
            
            // Create augmentation that transfers between different budgets
            $this->createCrossBudgetAugmentation($barangay, $budgets, $user, $allAppropriations, $augmentationCount + 1);
            $augmentationCount++;
            
            if ($augmentationCount >= $maxAugmentations) break;
        }
    }

    private function createCrossBudgetAugmentation($barangay, $budgets, $user, $allAppropriations, $index)
    {
        // Generate augmentation date (within the last 6 months)
        $augmentationDate = now()->subDays(rand(1, 180));
        
        // Select source budget (budget to transfer FROM)
        $sourceBudget = $budgets->random();
        
        // Select destination budget (budget to transfer TO) - must be different
        $destinationBudget = $budgets->where('id', '!=', $sourceBudget->id)->random();
        
        if (!$destinationBudget) {
            \Log::warning("No different budget found for cross-budget transfer in barangay {$barangay->id}");
            return;
        }
        
        // Create budget augmentation (attached to source budget)
        $augmentation = BudgetAugmentation::create([
            'barangay_id' => $barangay->id,
            'budget_id' => $sourceBudget->id, // Attach to source budget
            'ref_number' => 'AUG-' . $augmentationDate->format('y') . '-' . $augmentationDate->format('m') . '-' . str_pad($index, 3, '0', STR_PAD_LEFT),
            'augmentation_date' => $augmentationDate->format('Y-m'),
            'total_amount' => 0, // Will be calculated from details
            'remarks' => $this->generateRemarks($sourceBudget->description ?? 'Source Budget', $destinationBudget->description ?? 'Destination Budget', $index),
            'user_id' => $user->id
        ]);

        // Create augmentation details with cross-budget transfers
        $totalAmount = 0;
        $detailsCreated = 0;
        $maxDetails = 2; // Create up to 2 transfer details
        
        for ($j = 1; $j <= $maxDetails && $detailsCreated < $maxDetails; $j++) {
            // Find source appropriation (FROM budget)
            $sourceAppropriations = $allAppropriations->where('budget_id', $sourceBudget->id)
                ->where('amount', '>', 1000)
                ->values();
            
            if ($sourceAppropriations->count() === 0) break;
            
            $fromAppropriation = $sourceAppropriations->random();
            
            // Find destination appropriation (TO budget) - must be different budget
            $destinationAppropriations = $allAppropriations->where('budget_id', $destinationBudget->id)
                ->where('amount', '>', 0)
                ->values();
            
            if ($destinationAppropriations->count() === 0) break;
            
            $toAppropriation = $destinationAppropriations->random();
            
            // Refresh both appropriations to get current amounts
            $fromAppropriation->refresh();
            $toAppropriation->refresh();
            
            // Calculate transfer amount (not exceeding available amount, minimum 500)
            $maxTransferAmount = min($fromAppropriation->amount * 0.3, 3000); // Max 30% of source amount or 3000
            $transferAmount = max(500, min(rand(500, 2000), $maxTransferAmount));
            // Round to the nearest lower multiple of 100 for clean values
            $transferAmount = (int) (floor($transferAmount / 100) * 100);
            if ($transferAmount < 100) {
                $transferAmount = 100;
            }
            if ($transferAmount > $maxTransferAmount) {
                $transferAmount = (int) (floor($maxTransferAmount / 100) * 100);
            }
            
            if ($transferAmount > $fromAppropriation->amount) continue; // Skip if insufficient funds
            
            try {
                // Perform the actual transfer (deduct from FROM, add to TO)
                $fromAppropriation->decrement('amount', $transferAmount);
                $toAppropriation->increment('amount', $transferAmount);
                
                $totalAmount += $transferAmount;

                // Create augmentation detail using appropriation IDs
                $detailData = [
                    'budget_augmentation_id' => $augmentation->id,
                    'from_appropriation_id' => $fromAppropriation->id,
                    'to_appropriation_id' => $toAppropriation->id,
                    'amount' => $transferAmount,
                    'particulars' => "test data"
                ];

                BudgetAugmentationDetail::create($detailData);
                $detailsCreated++;
                
                
            } catch (\Exception $e) {
                // Log error and continue with next iteration
                \Log::warning('Failed to create augmentation detail: ' . $e->getMessage());
                continue;
            }
        }

        // Only proceed if we created at least 1 detail with positive amount
        if ($totalAmount > 0 && $detailsCreated >= 1) {
            // Update augmentation total amount
            $augmentation->update(['total_amount' => $totalAmount]);

            // Update source budget (decrement current amount since money is transferred out)
            $sourceBudget->decrement('current_amount', $totalAmount);
            
            // Update destination budget (increment current amount since money is transferred in)
            $destinationBudget->increment('current_amount', $totalAmount);
            
            // Update augmentation columns: source (negative), destination (positive)
            $sourceBudget->decrement('augmentation', $totalAmount);
            $destinationBudget->increment('augmentation', $totalAmount);
            
            \Log::info("Created cross-budget augmentation {$augmentation->id} with total amount {$totalAmount} - FROM budget {$sourceBudget->id} TO budget {$destinationBudget->id}");
        } else {
            // If no details were created or total amount is 0, delete the augmentation
            $augmentation->delete();
            \Log::warning("Failed to create augmentation details for cross-budget transfer");
        }
    }

    private function generateRemarks($sourceBudgetDescription, $destinationBudgetDescription, $index)
    {
        $remarks = [
            "test data"
        ];
        
        return $remarks[array_rand($remarks)];
    }

    private function getExpenseDescription($appropriation)
    {
        $parts = [];
        
        if ($appropriation->expenseClass && $appropriation->expenseClass->name) {
            $parts[] = $appropriation->expenseClass->name;
        }
        
        if ($appropriation->expenseType && $appropriation->expenseType->name) {
            $parts[] = $appropriation->expenseType->name;
        }
        
        if ($appropriation->expenseItem && $appropriation->expenseItem->name) {
            $parts[] = $appropriation->expenseItem->name;
        }
        
        return implode(' > ', $parts);
    }
} 