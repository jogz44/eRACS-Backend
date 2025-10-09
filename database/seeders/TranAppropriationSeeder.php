<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TranAppropriation;
use App\Models\Budget;
use App\Models\Barangay;
use App\Models\BarangayUser;
use App\Models\LibExpenseItem;
use App\Models\LibExpenseType;
use App\Models\LibExpenseClass;
use App\Models\LibFiscalYear;
use Faker\Factory as Faker;

class TranAppropriationSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();
        $now = now();
        $barangays = Barangay::all();

        foreach ($barangays as $barangay) {
            $budgets = Budget::where('barangay_id', $barangay->id)->get();
            $user = BarangayUser::where('barangay_id', $barangay->id)->first();

            if ($budgets->isEmpty() || !$user) {
                \Log::warning("Skipping Barangay {$barangay->id} (no budgets or user found)");
                continue;
            }

            foreach ($budgets as $budget) {
                
                $yearBudget = (int)LibFiscalYear::where('id', $budget->fiscal_year_id)->value('year');
                $remaining = $budget->current_amount;
                $totalAllocated = 0;

                $expenseClasses = LibExpenseClass::where('barangay_id', $barangay->id)
                    ->where('fiscal_year_id', $budget->fiscal_year_id);

                if ($yearBudget != 2025) {
                    $expenseClasses->where(function($q) {
                        $q->where('order', 0)
                        ->orWhere('order', 4)
                        ->orWhere('order', 5);
                    });
                }

                $expenseClasses = $expenseClasses->inRandomOrder()
                    ->take(3)
                    ->get();
                foreach ($expenseClasses as $expenseClass) {
                    
                    if (!$expenseClass) {
                        \Log::warning("Skipping Budget {$budget->id} (no expense class found)");
                        continue;
                    }


                    $expenseTypes = LibExpenseType::where('expense_class_id', $expenseClass->id);
                    if($yearBudget!=2025 && $expenseClass->order===0 ){
                        $expenseTypes->where(function($q) {
                            $q->where('order', 1)
                            ->orWhere('order', 2);
                        });
                    }elseif($yearBudget!=2025 && $expenseClass->order===5 ){
                        $expenseTypes->where(function($q) {
                            $q->where('order', 0);
                        });
                    }
                    $expenseTypes=$expenseTypes->inRandomOrder()
                        ->take(6)
                        ->get();
                    foreach ($expenseTypes as $expenseType) {


                        if($remaining < 0) {
                            \Log::info("Budget {$budget->id} fully allocated.");
                            break;
                        }elseif($remaining > (int) ($remaining * 0.09)) {
                            $allocationAmount=$faker->numberBetween(
                                    (int) ($remaining * 0.05),
                                    (int) ($remaining * 0.09)
                            );
                        }else{
                            $allocationAmount = min(
                                $faker->numberBetween(
                                    (int) ($remaining * 0.01),
                                    (int) ($remaining * 0.05)
                                ),
                                $faker->numberBetween(
                                    (int) ($remaining * 0.01),
                                    (int) ($remaining * 0.05)
                                )
                            );
                        }
                        if($remaining-$allocationAmount < (int) ($remaining * 0.005)) {
                            $allocationAmount=$remaining;
                        }
                        
                        if (!$expenseType) {
                            \Log::warning("Skipping ExpenseClass {$expenseClass->id} (no expense type found)");
                            continue;
                        }

                        $fiscalYear = LibFiscalYear::where('barangay_id', $barangay->id)
                            ->where('id', $budget->fiscal_year_id)
                            ->first();

                        if (!$fiscalYear) {
                            \Log::warning("Skipping Budget {$budget->id} (no fiscal year found)");
                            continue;
                        }

                        $year = (int) $fiscalYear->year; // ✅ ensure integer

                        if($allocationAmount>$budget->current_amount){
                            $allocationAmount=$budget->current_amount;
                        }

                        $expenseItem=LibExpenseItem::where('expense_type_id', $expenseType->id)->where('parent_item_id',null)->inRandomOrder()->first();
                        $expenseSubItem= null;
                        if($expenseItem){
                            $expenseSubItem=LibExpenseItem::where('parent_item_id', $expenseItem->id)
                                ->inRandomOrder()->first();
                        }
                        

                        TranAppropriation::create([
                            'barangay_id'      => $barangay->id,
                            'budget_id'        => $budget->id,
                            'expense_class_id' => $expenseClass->id,
                            'expense_type_id'  => $expenseType->id,
                            'expense_item_id'  => $expenseItem->id ?? null, // nullable
                            'expense_sub_item_id'  => $expenseItem ? ($expenseSubItem->id ?? null) : null, // nullable
                            'amount'           => $allocationAmount,
                            'transaction_date' => $faker->dateTimeBetween(
                                $now->copy()->setYear($year)->startOfYear(),
                                $now->copy()->setYear($year)->endOfYear()
                            ),
                            'status'           => 'committed',
                            'user_id'          => $user->id,
                        ]);

                        // Update budget (this looks unused since $totalAllocated is always 0)
                        if ($allocationAmount > 0) {
                            $budget->current_amount = max(0, $budget->current_amount - $allocationAmount);
                            $budget->save();

                            \Log::info("Barangay {$barangay->id} | Budget {$budget->id} updated", [
                                'sana matira money -> '    => $budget->current_amount - $allocationAmount,
                                'new_current_amount' => $budget->current_amount,
                                'allocation'         => $allocationAmount,
                            ]);
                        }
                    }
    
                }

                
            }
        }
    }
}
