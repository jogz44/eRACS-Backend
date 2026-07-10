<?php
namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Http\Controllers\AdminAuthController;
use App\Models\Budget;
use App\Models\TranAppropriation;
use App\Models\ContAppropriation;
use App\Models\ContApproAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Admin;

class ContinuingAppropriationController extends Controller
{
    public function index(Request $request)
    {
        // Log user activity
        // if ($request->user()) {
        //     AdminAuthController::logUserAction($request->user(),'Visited Appropriation Page' ,'Visited Appropriation Page');
        // }
        $query = TranAppropriation::select(
                        DB::raw('MIN(tran_appropriations.id) as id'),
                        'tran_appropriations.expense_class_id',
                        'tran_appropriations.expense_type_id',
                        'tran_appropriations.expense_item_id',
                        DB::raw('SUM(tran_appropriations.amount) as total_amount')
                    )
                    ->with(['expenseClass.fiscalYear', 'expenseType', 'expenseItem'])
                    ->where('tran_appropriations.barangay_id', $request->user()->barangay_id)
                    ->whereRelation('expenseClass.fiscalYear', 'year', '!=', now()->year)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                              ->from('cont_appro_accounts')
                              ->whereColumn('cont_appro_accounts.tranAppropriation_id', 'tran_appropriations.id')
                              ->where('cont_appro_accounts.status', 'active');
                    })
                    ->groupBy(
                        'tran_appropriations.expense_class_id',
                        'tran_appropriations.expense_type_id',
                        'tran_appropriations.expense_item_id'
                    );


        $detail = TranAppropriation::select(
                        DB::raw('MIN(tran_appropriations.id) as id'),
                        'tran_appropriations.expense_class_id',
                        'tran_appropriations.expense_type_id',
                        'tran_appropriations.expense_item_id',
                        DB::raw('SUM(ISNULL(tran_expense_details.amount, 0)) as details_amount')
                    )
                    ->leftJoin('tran_expense_details', 'tran_expense_details.appropriation_id', '=', 'tran_appropriations.id')
                    ->with(['expenseClass.fiscalYear', 'expenseType', 'expenseItem'])
                    ->where('tran_appropriations.barangay_id', $request->user()->barangay_id)
                    ->whereRelation('expenseClass.fiscalYear', 'year', '!=', now()->year)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                              ->from('cont_appro_accounts')
                              ->whereColumn('cont_appro_accounts.tranAppropriation_id', 'tran_appropriations.id')
                              ->where('cont_appro_accounts.status', 'active');
                    })
                    ->groupBy(
                        'tran_appropriations.expense_class_id',
                        'tran_appropriations.expense_type_id',
                        'tran_appropriations.expense_item_id'
                    );


        $totals = $query->get();
        $details = $detail->get();

        $rows = $totals->map(function ($o) use ($details) {
            $d = $details->first(fn($d) =>
                $d->expense_class_id == $o->expense_class_id &&
                $d->expense_type_id == $o->expense_type_id &&
                $d->expense_item_id == $o->expense_item_id
            );

            $details_amount = $d->details_amount ?? 0;

            return [
                'id' => $o->id,
                'year' => $o->expenseClass?->fiscalYear?->year,
                'expenseClass' => $o->expenseClass?->name,
                'expenseType' => $o->expenseType?->name,
                'expenseItem' => $o->expenseItem?->name,
                'expenseSubItem' => $o->expenseSubItem?->name,
                'total_amount' => (float) $o->total_amount,
                'details_amount' => (float) $details_amount,
                'remaining_amount' => (float) $o->total_amount - (float) $details_amount,
                // Add subitems array for frontend display
                'subItems' => $o->subItems ?? [],
            ];
        })
        ->filter(fn($row) => $row['remaining_amount'] != 0)
        ->values()
        ->toArray();

        return response()->json([
            'rows' => $rows,
        ]);
    }

    /**
     * Store a new continuing appropriation
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'fiscal_year_id' => 'required|exists:lib_fiscal_years,id',
            'expense_class' => 'required|string|max:255',
            'appropriation_amount' => 'required|numeric|min:0',
            'unappropriated_amount' => 'required|numeric|min:0',
            'continued_date' => 'required|date',
            'accounts' => 'required|array|min:1',
            'accounts.*.id' => 'required|exists:tran_appropriations,id',
            'accounts.*.balance' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            // Create the continuing appropriation
            $continuingAppropriation = ContAppropriation::create([
                'barangay_id' => $request->user()->barangay_id,
                'fiscal_year_id' => $validated['fiscal_year_id'],
                'description' => $validated['description'],
                'expense_class' => $validated['expense_class'],
                'appropriation_amount' => $validated['appropriation_amount'],
                'unappropriated_amount' => $validated['unappropriated_amount'],
                'continued_date' => $validated['continued_date'],
                'status' => 'draft',
                'user_id' => $request->user()->id,
            ]);

            // Create the continuing account records
            foreach ($validated['accounts'] as $account) {
                ContApproAccounts::create([
                    'contAppropriation_id' => $continuingAppropriation->id,
                    'tranAppropriation_id' => $account['id'],
                    'original_amount' => $account['balance'], // Store the original balance
                    'current_amount' => $account['balance'], // Initialize current amount with the same value
                    'continuingYear' => now()->year,
                    'status' => 'active',
                    'user_id' => $request->user()->id,
                ]);
            }

            DB::commit();

            // Log the action
            AdminAuthController::logUserAction(
                $request->user(),
                'Created Continuing Appropriation',
                "Created continuing appropriation: {$validated['description']} with amount ₱" . number_format($validated['appropriation_amount'], 2)
            );

            return response()->json([
                'status' => true,
                'message' => 'Continuing appropriation created successfully',
                'data' => $continuingAppropriation->load('continuingAccounts')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to create continuing appropriation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get continued accounts available for disbursement
     */
    public function getContinuedAccountsForDisbursement(Request $request)
    {
        try {
            $user = $request->user();
            // Get all continuing appropriation accounts that are active and have remaining balance
            $continuedAccounts = ContApproAccounts::with([
                'transactionAppropriation.expenseClass.fiscalYear',
                'transactionAppropriation.expenseType',
                'transactionAppropriation.expenseItem.childItems'
            ])
            ->whereHas('continuingAppropriation', function ($query) use ($user) {
                $query->where('status', 'committed');

                if (!($user instanceof \App\Models\Admin)) {
                    $query->where('barangay_id', $user->barangay_id);
                }
            })
            ->where('status', 'active')
            ->where('current_amount', '>', 0)
            ->get()
            ->map(function ($account) {
                $tranApp = $account->transactionAppropriation;

                // Get sub-items for this expense item
                $subItems = $tranApp->expenseItem?->childItems ?? collect();

                $result = [
                    'id' => $account->id,
                    'tranAppropriationId' => $tranApp->id,
                    'year' => $tranApp->expenseClass?->fiscalYear?->year,
                    'expenseClass' => $tranApp->expenseClass?->name,
                    'expenseType' => $tranApp->expenseType?->name,
                    'expenseItem' => $tranApp->expenseItem?->name,
                    'remaining_amount' => (float) $account->current_amount,
                    'continuingAppropriationId' => $account->contAppropriation_id,
                    'description' => $account->continuingAppropriation?->description ?? 'Continued from previous year',
                    'subItems' => $subItems->map(function($subItem) {
                        return [
                            'id' => $subItem->id,
                            'name' => $subItem->name,
                            'order' => $subItem->order,
                        ];
                    })->toArray()
                ];

                return $result;
            })
            ->filter(function($account) {
                return $account['expenseClass'] && $account['expenseType'] && $account['expenseItem'];
            })
            ->values()
            ->toArray();

            return response()->json([
                'status' => true,
                'data' => $continuedAccounts
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch continued accounts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET all continuing appropriations for the current barangay
     */
    public function getContinuingAppropriations(Request $request)
    {
        try {
            $user = $request->user();

            $query = ContAppropriation::with([
                'continuingAccounts.transactionAppropriation',
                'fiscalYear'
            ]);

            // Restrict only barangay users
            if (!($user instanceof Admin)) {
                $query->where('barangay_id', $user->barangay_id);
            }

            if ($request->has('fiscal_year_id')) {
                $query->where('fiscal_year_id', $request->fiscal_year_id);
            }

            $continuingAppropriations = $query->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    // Calculate total disbursed amount from continuing appropriation accounts
                    $totalDisbursed = 0;
                    foreach ($item->continuingAccounts as $account) {
                        $disbursedAmount = \App\Models\ContTranExpenseDetail::where('cont_appro_account_id', $account->id)
                            ->sum('amount');
                        $totalDisbursed += $disbursedAmount;
                    }

                    $availableAmount = (float) $item->appropriation_amount - (float) $totalDisbursed;

                    return [
                        'id' => $item->id,
                        'continued_date' => $item->continued_date->format('m/d/Y'),
                        'year' => $item->fiscalYear->year,
                        'expense_class' => $item->expense_class,
                        'description' => $item->description,
                        'appropriation' => (float) $item->appropriation_amount,
                        'total_appropriated' => (float) $totalDisbursed,
                        'unappropriated' => (float) $availableAmount,
                        'status' => $item->status,
                        'accounts' => $item->continuingAccounts->map(function ($account) {
                            $tranApp = $account->transactionAppropriation;
                            $accountNameParts = [
                                $tranApp->expenseClass?->name,
                                $tranApp->expenseType?->name,
                                $tranApp->expenseItem?->name,
                                $tranApp->expenseSubItem?->name
                            ];

                            return [
                                'id' => $account->id,
                                'balance' => (float) $account->current_amount,
                                'accountName' => implode(' > ', array_filter($accountNameParts)),
                                'expenseClass' => $tranApp->expenseClass?->name,
                                'expenseType' => $tranApp->expenseType?->name,
                                'expenseItem' => $tranApp->expenseItem?->name,
                                'expenseSubItem' => $tranApp->expenseSubItem?->name,
                                'subItems' => $tranApp->subItems ?? [],
                            ];
                        })
                    ];
                });

            return response()->json([
                'status' => true,
                'data' => $continuingAppropriations
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch continuing appropriations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the status of a continuing appropriation
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:draft,committed,reverted'
        ]);

        try {
            $user = $request->user();

            $query = ContAppropriation::query();

            if (!($user instanceof \App\Models\Admin)) {
                $query->where('barangay_id', $user->barangay_id);
            }

            $continuingAppropriation = $query->findOrFail($id);

            $continuingAppropriation->update(['status' => $validated['status']]);

            // Log the action
            AdminAuthController::logUserAction(
                $request->user(),
                'Updated Continuing Appropriation Status',
                "Updated continuing appropriation status to {$validated['status']}: {$continuingAppropriation->description}"
            );

            return response()->json([
                'status' => true,
                'message' => 'Status updated successfully',
                'data' => $continuingAppropriation
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get allocation history for a continuing appropriation
     */
    public function getAllocationHistory(Request $request, $id)
    {
        try {
            $continuingAppropriation = ContAppropriation::where('barangay_id', $request->user()->barangay_id)
                ->findOrFail($id);

            // Get allocations made for this continuing appropriation
            $allocationQuery = TranAppropriation::with([
                'expenseClass',
                'expenseType',
                'expenseItem'
            ]);

            if (!($user instanceof \App\Models\Admin)) {
                $allocationQuery->where('barangay_id', $user->barangay_id);
            }

            $allocations = $allocationQuery
                ->where('cont_appropriation_id', $continuingAppropriation->id)
                ->where('status', 'committed')
                ->orderBy('transaction_date', 'desc')
                ->get()
                ->groupBy(function($allocation) {
                    // Group by date to create sessions
                    return $allocation->transaction_date->format('Y-m-d');
                })
                ->map(function($dayAllocations, $date) {
                    return [
                        'session_id' => $date,
                        'created_at' => $dayAllocations->first()->transaction_date,
                        'allocations' => $dayAllocations->map(function($allocation) {
                            return [
                                'id' => $allocation->id,
                                'amount' => (float) $allocation->amount,
                                'expense_class_id' => $allocation->expense_class_id,
                                'expense_type_id' => $allocation->expense_type_id,
                                'expense_item_id' => $allocation->expense_item_id,
                                'expense_class_name' => $allocation->expenseClass?->name,
                                'expense_type_name' => $allocation->expenseType?->name,
                                'expense_item_name' => $allocation->expenseItem?->name,
                                'transaction_date' => $allocation->transaction_date
                            ];
                        })->toArray()
                    ];
                })
                ->values()
                ->toArray();

            $history = [
                'history' => $allocations
            ];

            return response()->json([
                'status' => true,
                'data' => $history
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch allocation history: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Commit allocations for a continuing appropriation
     */
    public function commitAllocation(Request $request, $id)
    {
        $validated = $request->validate([
            'allocations' => 'required|array',
            'allocations.*.id' => 'required',
            'allocations.*.type' => 'required|in:type,item',
            'allocations.*.amount' => 'required|numeric|min:0',
            'allocations.*.expense_class_id' => 'nullable|integer|exists:lib_expense_classes,id',
            'allocations.*.expense_type_id' => 'nullable|integer|exists:lib_expense_types,id',
            'allocations.*.expense_item_id' => 'nullable|integer|exists:lib_expense_items,id'
        ]);

        try {
            $continuingAppropriation = ContAppropriation::where('barangay_id', $request->user()->barangay_id)
                ->findOrFail($id);

            // Calculate existing allocations total for this continuing appropriation
            $existingAllocationsTotal = TranAppropriation::where('barangay_id', $request->user()->barangay_id)
                ->where('cont_appropriation_id', $continuingAppropriation->id)
                ->where('status', 'committed')
                ->sum('amount');

            // Calculate new total allocation amount
            $newTotalAllocation = array_sum(array_column($validated['allocations'], 'amount'));

            // Calculate net change (new total - existing total)
            $netChange = $newTotalAllocation - $existingAllocationsTotal;

            // Validate against unappropriated amount
            if ($netChange > $continuingAppropriation->unappropriated_amount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Net allocation change exceeds unappropriated amount. Net change: ₱' . number_format($netChange, 2) . ', Available: ₱' . number_format($continuingAppropriation->unappropriated_amount, 2)
                ], 422);
            }

            DB::beginTransaction();

            // Update or create appropriation records for the allocations
            foreach ($validated['allocations'] as $allocation) {
                // Check if there's already an allocation for this expense item/type
                $existingAllocation = TranAppropriation::where('barangay_id', $request->user()->barangay_id)
                    ->where('cont_appropriation_id', $continuingAppropriation->id)
                    ->where('expense_class_id', $allocation['expense_class_id'] ?? null)
                    ->where('expense_type_id', $allocation['expense_type_id'] ?? null)
                    ->where('expense_item_id', $allocation['expense_item_id'] ?? null)
                    ->where('status', 'committed')
                    ->first();

                if ($existingAllocation) {
                    // Update existing allocation
                    $existingAllocation->update([
                        'amount' => $allocation['amount'],
                        'transaction_date' => now(),
                        'user_id' => $request->user()->id,
                    ]);
                } else {
                    // Create new allocation
                    TranAppropriation::create([
                        'barangay_id' => $request->user()->barangay_id,
                        'budget_id' => null, // Continuing appropriations don't have a budget_id
                        'cont_appropriation_id' => $continuingAppropriation->id, // Link to specific continuing appropriation
                        'expense_class_id' => $allocation['expense_class_id'] ?? null,
                        'expense_type_id' => $allocation['expense_type_id'] ?? null,
                        'expense_item_id' => $allocation['expense_item_id'] ?? null,
                        'expense_sub_item_id' => $allocation['expense_sub_item_id'] ?? null,
                        'amount' => $allocation['amount'],
                        'transaction_date' => now(),
                        'status' => 'committed',
                        'user_id' => $request->user()->id,
                    ]);
                }
            }

            // Update the unappropriated amount based on net change
            $continuingAppropriation->update([
                'unappropriated_amount' => $continuingAppropriation->unappropriated_amount - $netChange
            ]);

            DB::commit();

            // Log the action
            AdminAuthController::logUserAction(
                $request->user(),
                'Committed Continuing Appropriation Allocations',
                "Committed allocations with net change of ₱" . number_format($netChange, 2) .
                " for continuing appropriation: {$continuingAppropriation->description}"
            );

            return response()->json([
                'status' => true,
                'message' => 'Allocations committed successfully',
                'data' => [
                    'id' => $continuingAppropriation->id,
                    'unappropriated_amount' => $continuingAppropriation->unappropriated_amount - $netChange
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to commit allocations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new budget without initial appropriations
     */
    public function storeBudget(Request $request)
    {
        $validated = $request->validate([
            'fiscal_year_id' => 'required|exists:lib_fiscal_years,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'description' => 'required|string|max:255',
            'original_amount' => 'required|numeric|min:0'
        ]);

        $budget = Budget::create([
            'barangay_id' => $request->user()->barangay_id,
            'fiscal_year_id' => $validated['fiscal_year_id'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'description' => $validated['description'],
            'original_amount' => $validated['original_amount'],
            'current_amount' => $validated['original_amount'], // Initialize with full amount
            'augmentation' => 0, // Initialize augmentation to 0
            'user_id' => $request->user()->id
        ]);

        // Log the budget creation
        AdminAuthController::logUserAction(
            $request->user(),
            'Created Budget',
            "Created new budget with amount ₱" . number_format($validated['original_amount'], 2) . " - " . $validated['description']
        );

        return response()->json($budget, 201);
    }

    // Get expense hierarchy for allocation
    public function getExpenseHierarchy(Request $request)
    {
        $request->validate([
            'fiscal_year_id' => 'required|exists:lib_fiscal_years,id',
            'budget_id' => 'nullable|exists:budgets,id'
        ]);

        $barangayId = $request->user()->barangay_id;
        $budgetId = $request->budget_id;

        $classes = LibExpenseClass::with(['types.items'])
            ->where('fiscal_year_id', $request->fiscal_year_id)
            ->get()
            ->map(function($class) use ($barangayId, $budgetId) {
                // Calculate allocated amount for this expense class
                $classQuery = TranAppropriation::where('barangay_id', $barangayId)
                    ->where('expense_class_id', $class->id)
                    ->where('status', 'committed');

                if ($budgetId) {
                    $classQuery->where('budget_id', $budgetId);
                }

                $classAllocatedAmount = $classQuery->sum('amount');

                return [
                    'id' => $class->id,
                    'name' => $class->name,
                    'isMainCategory' => true,
                    'amount' => (float) $classAllocatedAmount,
                    'children' => $class->types->map(function($type) use ($barangayId, $budgetId) {
                        // Calculate allocated amount for this expense type
                        $typeQuery = TranAppropriation::where('barangay_id', $barangayId)
                            ->where('expense_type_id', $type->id)
                            ->where('status', 'committed');

                        if ($budgetId) {
                            $typeQuery->where('budget_id', $budgetId);
                        }

                        $typeAllocatedAmount = $typeQuery->sum('amount');

                        return [
                            'id' => $type->id,
                            'name' => $type->name,
                            'isMainCategory' => false,
                            'amount' => (float) $typeAllocatedAmount,
                            'children' => $type->items->map(function($item) use ($barangayId, $budgetId) {
                                // Get the allocated amount for this expense item
                                $query = TranAppropriation::where('barangay_id', $barangayId)
                                    ->where('expense_item_id', $item->id)
                                    ->where('status', 'committed');

                                // If budget_id is provided, filter by that specific budget
                                if ($budgetId) {
                                    $query->where('budget_id', $budgetId);
                                }

                                $allocatedAmount = $query->sum('amount');

                                return [
                                    'id' => $item->id,
                                    'name' => $item->name,
                                    'isMainCategory' => false,
                                    'amount' => (float) $allocatedAmount
                                ];
                            })
                        ];
                    })
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $classes
        ]);
    }

    // FIXED: Save allocation from modal - this is the key fix
    public function saveAllocation(Request $request, Budget $budget)
    {
       $validated = $request->validate([
        'allocations' => 'required|array',
        'allocations.*.id' => 'required',
        'allocations.*.type' => 'required|in:class,type,item',
        'allocations.*.amount' => 'required|numeric|min:0',
        'allocations.*.expense_class_id' => 'nullable|integer|exists:lib_expense_classes,id',
        'allocations.*.expense_type_id' => 'nullable|integer|exists:lib_expense_types,id',
        'allocations.*.expense_item_id' => 'nullable|integer|exists:lib_expense_items,id'
        ]);

        // Get existing allocations for this budget
        $existingAllocations = TranAppropriation::where('budget_id', $budget->id)
            ->where('barangay_id', $request->user()->barangay_id)
            ->get();

        $existingTotal = $existingAllocations->sum('amount');

        // Calculate total of new allocations
        $newTotal = array_sum(array_column($validated['allocations'], 'amount'));

        // Calculate net change (new total - existing total)
        $netChange = $newTotal - $existingTotal;

        \Log::info('Allocation validation', [
            'budget_id' => $budget->id,
            'budget_current_amount' => $budget->current_amount,
            'existing_total' => $existingTotal,
            'new_total' => $newTotal,
            'net_change' => $netChange,
            'allocations' => $validated['allocations']
        ]);

        // FIXED: Check net change against current_amount (available budget)
        if ($netChange > $budget->current_amount) {
            \Log::warning('Net change exceeds available budget', [
                'net_change' => $netChange,
                'available_budget' => $budget->current_amount,
                'difference' => $netChange - $budget->current_amount
            ]);

            return response()->json([
                'status' => false,
                'message' => sprintf(
                    'Net change exceeds available budget by ₱%s. Available: ₱%s, Net Change: ₱%s',
                    number_format($netChange - $budget->current_amount, 2),
                    number_format($budget->current_amount, 2),
                    number_format($netChange, 2)
                )
            ], 422);
        }

        return DB::transaction(function () use ($validated, $budget, $request, $netChange, $existingAllocations) {
            $appropriations = [];

            // Delete existing allocations for this budget
            $existingAllocations->each->delete();

            // Create new allocations
            foreach ($validated['allocations'] as $allocation) {
                $appropriationData = [
                    'barangay_id' => $request->user()->barangay_id,
                    'budget_id' => $budget->id,
                    'amount' => $allocation['amount'],
                    'transaction_date' => now(),
                    'status' => 'committed',
                    'user_id' => $request->user()->id,
                    'expense_class_id' => $allocation['expense_class_id'] ?? null,
                    'expense_type_id' => $allocation['expense_type_id'] ?? null,
                    'expense_item_id' => $allocation['expense_item_id'] ?? null,
                    'expense_sub_item_id' => $allocation['expense_sub_item_id'] ?? null
                ];

                $appropriations[] = TranAppropriation::create($appropriationData);

                AdminAuthController::logUserAction(
                    $request->user(),
                    'Updated Appropriation',
                    "Set appropriation amount to ₱" . number_format($allocation['amount'], 2) .
                    " for budget: " . $budget->description
                );
            }

            // Update budget's current amount by the net change
            $budget->current_amount = $budget->current_amount - $netChange;
            $budget->save();

            \Log::info('Budget updated after allocation', [
                'budget_id' => $budget->id,
                'new_current_amount' => $budget->current_amount,
                'net_change' => $netChange
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Allocation saved successfully',
                'budget' => $budget->fresh(),
                'appropriations' => $appropriations,
                'net_change' => $netChange,
                'updated_amount' => $budget->current_amount
            ]);
        });
    }
}
