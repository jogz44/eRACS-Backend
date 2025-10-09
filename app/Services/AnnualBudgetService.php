<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\AnnualBudgetDetail;
use App\Models\BarangayUser;
use App\Models\LibFiscalYear;
use App\BudgetType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class AnnualBudgetService
{
    /**
     * Create a new annual budget with details
     */
    public function createAnnualBudget(array $data, BarangayUser $user): Budget
    {
        return DB::transaction(function () use ($data, $user) {
            // Create the main budget record
            $budget = Budget::create([
                'barangay_id' => $user->barangay_id,
                'fiscal_year_id' => $data['fiscal_year_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'description' => $data['description'] ?? 'Annual Budget',
                'budget_type' => BudgetType::ANNUAL,
                'status' => 'draft',
                'original_amount' => $data['original_amount'],
                'current_amount' => $data['original_amount'],
                'augmentation' => 0,
                'return_amount' => 0,
                'user_id' => $user->id
            ]);

            // Create annual budget details if provided
            if (isset($data['details']) && is_array($data['details'])) {
                foreach ($data['details'] as $detailData) {
                    $this->createAnnualBudgetDetail($budget, $detailData);
                }
            }

            return $budget->load('annualBudgetDetail');
        });
    }

    /**
     * Create annual budget detail
     */
    public function createAnnualBudgetDetail(Budget $budget, array $detailData): AnnualBudgetDetail
    {
        $detailData['budget_id'] = $budget->id;
        $detailData['barangay_id'] = $budget->barangay_id;
        $detailData['remaining_amount'] = $detailData['allocated_amount'];

        return AnnualBudgetDetail::create($detailData);
    }

    /**
     * Get annual budgets for a barangay and fiscal year
     */
    public function getAnnualBudgets(int $barangayId, int $fiscalYearId): Collection
    {
        return Budget::annual()
            ->where('barangay_id', $barangayId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->with(['annualBudgetDetail', 'fiscalYear', 'user'])
            ->get();
    }

    /**
     * Get active annual budget for a barangay and fiscal year
     */
    public function getActiveAnnualBudget(int $barangayId, int $fiscalYearId): ?Budget
    {
        return Budget::annual()
            ->active()
            ->where('barangay_id', $barangayId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->with(['annualBudgetDetail', 'fiscalYear'])
            ->first();
    }

    /**
     * Approve annual budget
     */
    public function approveAnnualBudget(Budget $budget, BarangayUser $approver): Budget
    {
        if (!$budget->isAnnual()) {
            throw new \InvalidArgumentException('Budget must be an annual budget');
        }

        return DB::transaction(function () use ($budget, $approver) {
            $budget->approve($approver);
            
            // Approve all related annual budget details
            $budget->annualBudgetDetail?->approve($approver);
            
            return $budget->fresh();
        });
    }

    /**
     * Activate annual budget
     */
    public function activateAnnualBudget(Budget $budget): Budget
    {
        if (!$budget->isAnnual()) {
            throw new \InvalidArgumentException('Budget must be an annual budget');
        }

        return DB::transaction(function () use ($budget) {
            $budget->activate();
            $budget->annualBudgetDetail?->activate();
            
            return $budget->fresh();
        });
    }

    /**
     * Get annual budget summary for a barangay and fiscal year
     */
    public function getAnnualBudgetSummary(int $barangayId, int $fiscalYearId): array
    {
        $budgets = $this->getAnnualBudgets($barangayId, $fiscalYearId);
        
        return [
            'total_allocated' => $budgets->sum('original_amount'),
            'total_utilized' => $budgets->sum(function ($budget) {
                return $budget->original_amount - $budget->current_amount;
            }),
            'total_remaining' => $budgets->sum('current_amount'),
            'total_augmentation' => $budgets->sum('augmentation'),
            'budget_count' => $budgets->count(),
            'status_breakdown' => $budgets->groupBy('status')->map->count()
        ];
    }

    /**
     * Update annual budget utilization
     */
    public function updateUtilization(Budget $budget, float $amount): void
    {
        if (!$budget->isAnnual()) {
            throw new \InvalidArgumentException('Budget must be an annual budget');
        }

        DB::transaction(function () use ($budget, $amount) {
            $budget->decrement('current_amount', $amount);
            $budget->annualBudgetDetail?->updateUtilization($amount);
        });
    }

    /**
     * Check if annual budget can be created for fiscal year
     */
    public function canCreateAnnualBudget(int $barangayId, int $fiscalYearId): bool
    {
        $existingBudget = Budget::annual()
            ->where('barangay_id', $barangayId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereIn('status', ['draft', 'approved', 'active'])
            ->exists();

        return !$existingBudget;
    }

    /**
     * Get annual budget categories
     */
    public function getBudgetCategories(): array
    {
        return [
            'General Fund',
            'Special Education Fund',
            'Economic Development Fund',
            'Disaster Risk Reduction Fund',
            'Gender and Development Fund',
            'Barangay Development Fund',
            'Other Special Funds'
        ];
    }

    /**
     * Get sources of funds
     */
    public function getSourcesOfFunds(): array
    {
        return [
            'Internal Revenue Allotment (IRA)',
            'Local Tax Revenue',
            'Business Tax',
            'Real Property Tax',
            'Service Fees',
            'Grants and Donations',
            'Other Local Revenue'
        ];
    }
}

