<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\SupplementalBudgetDetail;
use App\Models\BarangayUser;
use App\Models\LibFiscalYear;
use App\BudgetType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class SupplementalBudgetService
{
    /**
     * Create a new supplemental budget with details
     */
    public function createSupplementalBudget(array $data, BarangayUser $user): Budget
    {
        return DB::transaction(function () use ($data, $user) {
            // Create the main budget record
            $budget = Budget::create([
                'barangay_id' => $user->barangay_id,
                'fiscal_year_id' => $data['fiscal_year_id'],
                'start_date' => $data['start_date'] ?? now()->startOfYear(),
                'end_date' => $data['end_date'] ?? now()->endOfYear(),
                'description' => $data['description'] ?? 'Supplemental Budget',
                'budget_type' => BudgetType::SUPPLEMENTAL,
                'status' => 'draft',
                'effective_date' => $data['effective_date'] ?? now(),
                'original_amount' => $data['original_amount'],
                'current_amount' => $data['original_amount'],
                'augmentation' => 0,
                'return_amount' => 0,
                'user_id' => $user->id
            ]);

            // Create supplemental budget details if provided
            if (isset($data['details']) && is_array($data['details'])) {
                foreach ($data['details'] as $detailData) {
                    $this->createSupplementalBudgetDetail($budget, $detailData);
                }
            }

            return $budget->load('supplementalBudgetDetail');
        });
    }

    /**
     * Create supplemental budget detail
     */
    public function createSupplementalBudgetDetail(Budget $budget, array $detailData): SupplementalBudgetDetail
    {
        $detailData['budget_id'] = $budget->id;
        $detailData['barangay_id'] = $budget->barangay_id;
        $detailData['remaining_amount'] = $detailData['supplement_amount'];
        $detailData['request_date'] = $detailData['request_date'] ?? now();

        return SupplementalBudgetDetail::create($detailData);
    }

    /**
     * Get supplemental budgets for a barangay and fiscal year
     */
    public function getSupplementalBudgets(int $barangayId, int $fiscalYearId): Collection
    {
        return Budget::supplemental()
            ->where('barangay_id', $barangayId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->with(['supplementalBudgetDetail', 'fiscalYear', 'user'])
            ->get();
    }

    /**
     * Get active supplemental budgets for a barangay and fiscal year
     */
    public function getActiveSupplementalBudgets(int $barangayId, int $fiscalYearId): Collection
    {
        return Budget::supplemental()
            ->active()
            ->where('barangay_id', $barangayId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->with(['supplementalBudgetDetail', 'fiscalYear'])
            ->get();
    }

    /**
     * Get emergency supplemental budgets
     */
    public function getEmergencySupplementalBudgets(int $barangayId, int $fiscalYearId): Collection
    {
        return Budget::supplemental()
            ->where('barangay_id', $barangayId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereHas('supplementalBudgetDetail', function ($query) {
                $query->where('urgency_level', 'critical');
            })
            ->with(['supplementalBudgetDetail', 'fiscalYear'])
            ->get();
    }

    /**
     * Approve supplemental budget
     */
    public function approveSupplementalBudget(Budget $budget, BarangayUser $approver): Budget
    {
        if (!$budget->isSupplemental()) {
            throw new \InvalidArgumentException('Budget must be a supplemental budget');
        }

        return DB::transaction(function () use ($budget, $approver) {
            $budget->approve($approver);
            $budget->supplementalBudgetDetail?->approve($approver);
            
            return $budget->fresh();
        });
    }

    /**
     * Activate supplemental budget
     */
    public function activateSupplementalBudget(Budget $budget): Budget
    {
        if (!$budget->isSupplemental()) {
            throw new \InvalidArgumentException('Budget must be a supplemental budget');
        }

        return DB::transaction(function () use ($budget) {
            $budget->activate();
            $budget->supplementalBudgetDetail?->activate();
            
            return $budget->fresh();
        });
    }

    /**
     * Get supplemental budget summary for a barangay and fiscal year
     */
    public function getSupplementalBudgetSummary(int $barangayId, int $fiscalYearId): array
    {
        $budgets = $this->getSupplementalBudgets($barangayId, $fiscalYearId);
        
        return [
            'total_supplement' => $budgets->sum('original_amount'),
            'total_utilized' => $budgets->sum(function ($budget) {
                return $budget->original_amount - $budget->current_amount;
            }),
            'total_remaining' => $budgets->sum('current_amount'),
            'budget_count' => $budgets->count(),
            'status_breakdown' => $budgets->groupBy('status')->map->count(),
            'urgency_breakdown' => $budgets->map(function ($budget) {
                return $budget->supplementalBudgetDetail?->urgency_level;
            })->filter()->countBy()
        ];
    }

    /**
     * Update supplemental budget utilization
     */
    public function updateUtilization(Budget $budget, float $amount): void
    {
        if (!$budget->isSupplemental()) {
            throw new \InvalidArgumentException('Budget must be a supplemental budget');
        }

        DB::transaction(function () use ($budget, $amount) {
            $budget->decrement('current_amount', $amount);
            $budget->supplementalBudgetDetail?->updateUtilization($amount);
        });
    }

    /**
     * Check if supplemental budget can be created for fiscal year
     */
    public function canCreateSupplementalBudget(int $barangayId, int $fiscalYearId): bool
    {
        // Check if fiscal year is still active
        $fiscalYear = LibFiscalYear::where('id', $fiscalYearId)
            ->where('barangay_id', $barangayId)
            ->first();

        if (!$fiscalYear || $fiscalYear->end_date->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get supplement types
     */
    public function getSupplementTypes(): array
    {
        return [
            'Emergency',
            'Additional Allocation',
            'Reallocation',
            'Unexpected Revenue',
            'Savings Utilization',
            'Special Project',
            'Disaster Response',
            'Other'
        ];
    }

    /**
     * Get sources of supplement
     */
    public function getSourcesOfSupplement(): array
    {
        return [
            'Unexpected Revenue',
            'Savings from Other Projects',
            'Emergency Fund',
            'Disaster Fund',
            'Grants and Donations',
            'Reallocation from Other Budgets',
            'Additional IRA',
            'Other Sources'
        ];
    }

    /**
     * Get urgency levels
     */
    public function getUrgencyLevels(): array
    {
        return [
            'low' => 'Low - Can wait for next budget cycle',
            'medium' => 'Medium - Should be addressed soon',
            'high' => 'High - Needs immediate attention',
            'critical' => 'Critical - Emergency situation'
        ];
    }

    /**
     * Check for expired supplemental budgets
     */
    public function checkExpiredBudgets(): Collection
    {
        return Budget::supplemental()
            ->active()
            ->whereHas('supplementalBudgetDetail', function ($query) {
                $query->where('expiry_date', '<', now())
                      ->where('supplement_status', 'active');
            })
            ->with('supplementalBudgetDetail')
            ->get();
    }

    /**
     * Expire supplemental budgets that have passed their expiry date
     */
    public function expireOverdueBudgets(): int
    {
        $expiredBudgets = $this->checkExpiredBudgets();
        
        foreach ($expiredBudgets as $budget) {
            $budget->supplementalBudgetDetail?->expire();
            $budget->close();
        }

        return $expiredBudgets->count();
    }

    /**
     * Get supplemental budget by urgency level
     */
    public function getBudgetsByUrgency(int $barangayId, int $fiscalYearId, string $urgencyLevel): Collection
    {
        return Budget::supplemental()
            ->where('barangay_id', $barangayId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereHas('supplementalBudgetDetail', function ($query) use ($urgencyLevel) {
                $query->where('urgency_level', $urgencyLevel);
            })
            ->with(['supplementalBudgetDetail', 'fiscalYear'])
            ->get();
    }
}

