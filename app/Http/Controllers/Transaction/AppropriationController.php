<?php
namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Http\Controllers\AdminAuthController;
use App\Models\Budget;
use App\Models\TranAppropriation;
use App\Models\TranExpenseDetail;
use App\Models\LibFiscalYear;
use App\Models\LibExpenseClass;
use App\Models\LibExpenseType;
use App\Models\LibExpenseItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppropriationController extends Controller
{
    public function index(Request $request)
    {
        // Removed page visit logging as requested

        $request->validate([
            'year' => 'nullable|integer',
            'status' => 'nullable|in:draft,committed,reverted',
            'search' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'barangay_id' => 'nullable|exists:barangays,id'
        ]);

        // Get base query
        $query = Budget::with(['tranAppropriations.expenseType', 'fiscalYear', 'barangay']);
        
        // Check if user is admin (from admin guard) or barangay user with admin role
        $isAdmin = false;
        
        // Try to get admin user first
        try {
            $adminUser = $request->user('admin');
            if ($adminUser) {
                $isAdmin = true;
            }
        } catch (\Exception $e) {
            // Not an admin user, check if barangay user is admin
            $barangayUser = $request->user('barangay');
            if ($barangayUser && $barangayUser->role === 'admin') {
                $isAdmin = true;
            }
        }
        
        // Filter by barangay - admin can view all, regular users only see their barangay
        if ($request->barangay_id && $isAdmin) {
            // Admin can filter by specific barangay
            $query->where('barangay_id', $request->barangay_id);
        } else {
            // Regular users only see their barangay
            $user = $request->user('barangay');
            if ($user) {
                $query->where('barangay_id', $user->barangay_id);
            }
        }

        // Apply year filter
        if ($request->year) {
            $query->whereHas('fiscalYear', function($q) use ($request) {
                $q->where('year', $request->year);
            });
        }

        // Apply other filters
        if ($request->search) {
            $query->where('description', 'like', '%'.$request->search.'%');
        }

        if ($request->date_from) {
            $query->where('start_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->where('end_date', '<=', $request->date_to);
        }

        // Get budgets with their total appropriations
        $budgets = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function($budget) {
                $hasAllocations = $budget->tranAppropriations->isNotEmpty();
                
                // Calculate total available budget (original + augmentation)
                $totalAvailable = (float)$budget->original_amount + (float)$budget->augmentation;

                // Calculate total appropriated amount using lowest level logic
                $totalAppropriated = 0;
                $processedItems = [];
                $processedTypes = [];
                $processedClasses = [];

                foreach ($budget->tranAppropriations as $appropriation) {
                    if ($appropriation->expense_sub_item_id) {
                        if (!in_array($appropriation->expense_item_id, $processedItems)) {
                            $processedItems[] = $appropriation->expense_item_id;
                        }
                        $totalAppropriated += $appropriation->amount;
                    } elseif ($appropriation->expense_item_id && !in_array($appropriation->expense_item_id, $processedItems)) {
                        $processedItems[] = $appropriation->expense_item_id;
                        if (!in_array($appropriation->expense_type_id, $processedTypes)) {
                            $processedTypes[] = $appropriation->expense_type_id;
                        }
                        $totalAppropriated += $appropriation->amount;
                    } elseif ($appropriation->expense_type_id && !in_array($appropriation->expense_type_id, $processedTypes)) {
                        $processedTypes[] = $appropriation->expense_type_id;
                        if (!in_array($appropriation->expense_class_id, $processedClasses)) {
                            $processedClasses[] = $appropriation->expense_class_id;
                        }
                        $totalAppropriated += $appropriation->amount;
                    } elseif ($appropriation->expense_class_id && !in_array($appropriation->expense_class_id, $processedClasses)) {
                        $processedClasses[] = $appropriation->expense_class_id;
                        $totalAppropriated += $appropriation->amount;
                    } elseif (!$appropriation->expense_class_id && !$appropriation->expense_type_id && !$appropriation->expense_item_id && !$appropriation->expense_sub_item_id) {
                        // Count unappropriated funds (all expense fields are null)
                        $totalAppropriated += $appropriation->amount;
                    }
                }

                // Calculate unappropriated amount based on budget type
                $isSupplemental = str_contains(strtolower($budget->description), 'supplemental');

                if ($isSupplemental) {
                    // For supplemental budgets, unappropriated = current_amount (reflects actual available funds after allocations)
                    $unappropriated = (float)$budget->current_amount;
                } else {
                    // For annual budgets, unappropriated = current_amount (reflects actual available funds)
                    $unappropriated = (float)$budget->current_amount;
                }
                


                return [
                    'id' => $budget->id,
                    'date' => $budget->created_at->format('Y-m-d'),
                    'description' => $budget->description,
                    'amount' => $totalAvailable,
                    'current_amount' => (float)$budget->current_amount,
                    'unappropriated' => $unappropriated,
                    'fiscal_year' => $budget->fiscalYear->year,
                    'barangay_name' => $budget->barangay->name ?? null,
                    'barangay_id' => $budget->barangay_id,
                    'allocations' => $budget->tranAppropriations->map(function($tranAppropriations) {
                        return [
                            'id' => $tranAppropriations->id,
                            'amount' => (float)$tranAppropriations->amount,
                            'expense_type' => $tranAppropriations->expense_type_id ? ($tranAppropriations->expenseType->name ?? null) : 'Unappropriated'
                        ];
                    })
                ];
            });

        // Calculate total available based on filtered results
        $totalAvailable = $budgets->sum('amount');

        return response()->json([
            'status' => true,
            'data' => $budgets,
            'total_available' => $totalAvailable
        ]);
    }

    /**
     * Admin version of index - can view all barangay budgets
     */
    public function adminIndex(Request $request)
    {
        $request->validate([
            'year' => 'nullable|integer',
            'status' => 'nullable|in:draft,committed,reverted',
            'search' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'barangay_id' => 'nullable|exists:barangays,id'
        ]);

        // Get base query - admin can see all barangays
        $query = Budget::with(['tranAppropriations.expenseType', 'fiscalYear', 'barangay']);
        
        // Filter by specific barangay if requested
        if ($request->barangay_id) {
            $query->where('barangay_id', $request->barangay_id);
        }

        // Apply year filter
        if ($request->year) {
            $query->whereHas('fiscalYear', function($q) use ($request) {
                $q->where('year', $request->year);
            });
        }

        // Apply other filters
        if ($request->search) {
            $query->where('description', 'like', '%'.$request->search.'%');
        }

        if ($request->date_from) {
            $query->where('start_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->where('end_date', '<=', $request->date_to);
        }

        // Get budgets with their total appropriations
        $budgets = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function($budget) {
                $hasAllocations = $budget->tranAppropriations->isNotEmpty();
                
                // Calculate total available budget (original + augmentation)
                $totalAvailable = (float)$budget->original_amount + (float)$budget->augmentation;

                // Calculate total appropriated amount using lowest level logic
                $totalAppropriated = 0;
                $processedItems = [];
                $processedTypes = [];
                $processedClasses = [];

                foreach ($budget->tranAppropriations as $appropriation) {
                    if ($appropriation->expense_sub_item_id) {
                        if (!in_array($appropriation->expense_item_id, $processedItems)) {
                            $processedItems[] = $appropriation->expense_item_id;
                        }
                        $totalAppropriated += $appropriation->amount;
                    } elseif ($appropriation->expense_item_id && !in_array($appropriation->expense_item_id, $processedItems)) {
                        $processedItems[] = $appropriation->expense_item_id;
                        if (!in_array($appropriation->expense_type_id, $processedTypes)) {
                            $processedTypes[] = $appropriation->expense_type_id;
                        }
                        $totalAppropriated += $appropriation->amount;
                    } elseif ($appropriation->expense_type_id && !in_array($appropriation->expense_type_id, $processedTypes)) {
                        $processedTypes[] = $appropriation->expense_type_id;
                        if (!in_array($appropriation->expense_class_id, $processedClasses)) {
                            $processedClasses[] = $appropriation->expense_class_id;
                        }
                        $totalAppropriated += $appropriation->amount;
                    } elseif ($appropriation->expense_class_id && !in_array($appropriation->expense_class_id, $processedClasses)) {
                        $processedClasses[] = $appropriation->expense_class_id;
                        $totalAppropriated += $appropriation->amount;
                    } elseif (!$appropriation->expense_class_id && !$appropriation->expense_type_id && !$appropriation->expense_item_id && !$appropriation->expense_sub_item_id) {
                        // Count unappropriated funds (all expense fields are null)
                        $totalAppropriated += $appropriation->amount;
                    }
                }
                
                // Calculate unappropriated amount based on budget type
                $isSupplemental = str_contains(strtolower($budget->description), 'supplemental');
                
                if ($isSupplemental) {
                    // For supplemental budgets, unappropriated = current_amount (reflects actual available funds after allocations)
                    $unappropriated = (float)$budget->current_amount;
                } else {
                    // For annual budgets, unappropriated = current_amount (reflects actual available funds)
                    $unappropriated = (float)$budget->current_amount;
                }

                return [
                    'id' => $budget->id,
                    'date' => $budget->created_at->format('Y-m-d'),
                    'description' => $budget->description,
                    'amount' => $totalAvailable,
                    'current_amount' => (float)$budget->current_amount,
                    'unappropriated' => $unappropriated,
                    'fiscal_year' => $budget->fiscalYear->year,
                    'barangay_name' => $budget->barangay->name ?? null,
                    'barangay_id' => $budget->barangay_id,
                    'allocations' => $budget->tranAppropriations->map(function($tranAppropriations) {
                        return [
                            'id' => $tranAppropriations->id,
                            'amount' => (float)$tranAppropriations->amount,
                            'expense_type' => $tranAppropriations->expense_type_id ? ($tranAppropriations->expenseType->name ?? null) : 'Unappropriated'
                        ];
                    })
                ];
            });

        // Calculate total available based on filtered results
        $totalAvailable = $budgets->sum('amount');

        return response()->json([
            'status' => true,
            'data' => $budgets,
            'total_available' => $totalAvailable
        ]);
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
            'original_amount' => 'required|numeric|min:0',
            'barangay_id' => 'nullable|exists:barangays,id'
        ]);

        // Determine barangay_id based on user type
        $barangayId = null;
        if ($request->barangay_id) {
            // Admin user providing barangay_id
            $barangayId = $request->barangay_id;
        } else {
            // Regular user - use their barangay_id
            $barangayId = $request->user()->barangay_id;
        }

        $budget = Budget::create([
            'barangay_id' => $barangayId,
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
            sprintf(
                'Created budget "%s" with amount ₱%s',
                $validated['description'],
                number_format($validated['original_amount'], 2)
            )
        );

        return response()->json($budget, 201);
    }

    // Get expense hierarchy for allocation
    public function getExpenseHierarchy(Request $request)
    {
        $request->validate([
            'fiscal_year_id' => 'nullable|exists:lib_fiscal_years,id',
            'year' => 'nullable|integer|min:2000|max:2100',
            'budget_id' => 'nullable|exists:budgets,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'budget_type' => 'nullable|in:annual,supplemental,all'
        ]);

        // Allow admin to specify barangay_id; fallback to authenticated user's barangay
        $barangayId = $request->input('barangay_id') ?: optional($request->user())->barangay_id;
        $budgetId = $request->budget_id;
        $year = $request->input('year');
        $fiscalYearId = $request->input('fiscal_year_id');
        $budgetType = $request->input('budget_type', 'all');

        // Determine fiscal year ID from year if not provided
        if (!$fiscalYearId && $year) {
            $fiscalYear = LibFiscalYear::where('barangay_id', $barangayId)
                ->where('year', $year)
                ->first();
            if ($fiscalYear) {
                $fiscalYearId = $fiscalYear->id;
            }
        }

        // If still no fiscal year ID, use current year
        if (!$fiscalYearId) {
            $currentYear = now()->year;
            $fiscalYear = LibFiscalYear::where('barangay_id', $barangayId)
                ->where('year', $currentYear)
                ->first();
            if ($fiscalYear) {
                $fiscalYearId = $fiscalYear->id;
            }
        }

        if (!$fiscalYearId) {
            return response()->json([
                'status' => false,
                'message' => 'No fiscal year found for the specified criteria'
            ], 400);
        }

        // First, get the budget IDs that match the budget type filter
        $budgetIds = [];
        if ($budgetType !== 'all') {
            $budgetQuery = Budget::where('barangay_id', $barangayId);
            if ($fiscalYearId) {
                $budgetQuery->where('fiscal_year_id', $fiscalYearId);
            }
            
            if ($budgetType === 'annual') {
                $budgetQuery->where('description', 'like', '%annual%');
            } elseif ($budgetType === 'supplemental') {
                $budgetQuery->where('description', 'like', '%supplemental%');
            }
            
            $budgetIds = $budgetQuery->pluck('id')->toArray();
        }

        $classes = LibExpenseClass::with(['types.items' => function($query) {
            $query->with(['childItems'])->whereNull('parent_item_id'); // Only load root items, not sub-items
        }])
            ->where('fiscal_year_id', $fiscalYearId)
            ->orderBy('order')
            ->get()
            ->map(function($class) use ($barangayId, $budgetId, $budgetIds, $budgetType) {
                // Calculate allocated amount for this expense class
                $classQuery = TranAppropriation::where('barangay_id', $barangayId)
                    ->where('expense_class_id', $class->id)
                    ->where('status', 'committed');

                if ($budgetId) {
                    $classQuery->where('budget_id', $budgetId);
                } elseif ($budgetType !== 'all' && !empty($budgetIds)) {
                    $classQuery->whereIn('budget_id', $budgetIds);
                }

                $classAllocatedAmount = $classQuery->sum('amount');

                // Get budget source information for expense class
                $classBudgetSource = 'Annual Budget'; // Default
                if ($budgetType === 'annual') {
                    $classBudgetSource = 'Annual Budget';
                } elseif ($budgetType === 'supplemental') {
                    $classBudgetSource = 'Supplemental Budget';
                } else {
                    // For 'all', determine based on the budgets that have allocations
                    if ($budgetId) {
                        $budget = Budget::find($budgetId);
                        if ($budget && $budget->description) {
                            $description = strtolower($budget->description);
                            if (strpos($description, 'supplemental') !== false) {
                                $classBudgetSource = 'Supplemental Budget';
                            } elseif (strpos($description, 'annual') !== false) {
                                $classBudgetSource = 'Annual Budget';
                            }
                        }
                    } else {
                        // Check if there are multiple budgets with different sources
                        $budgets = Budget::whereHas('tranAppropriations', function($q) use ($barangayId, $class) {
                            $q->where('barangay_id', $barangayId)
                              ->where('expense_class_id', $class->id)
                              ->where('status', 'committed');
                        })->get();
                        
                        if ($budgets->count() > 0) {
                            $hasSupplemental = $budgets->filter(function($budget) {
                                return strpos(strtolower($budget->description), 'supplemental') !== false;
                            })->count() > 0;
                            
                            $hasAnnual = $budgets->filter(function($budget) {
                                return strpos(strtolower($budget->description), 'annual') !== false;
                            })->count() > 0;
                            
                            if ($hasSupplemental && $hasAnnual) {
                                $classBudgetSource = 'Mixed';
                            } elseif ($hasSupplemental) {
                                $classBudgetSource = 'Supplemental Budget';
                            } elseif ($hasAnnual) {
                                $classBudgetSource = 'Annual Budget';
                            }
                        }
                    }
                }

                return [
                    'id' => $class->id,
                    'name' => $class->name,
                    'isMainCategory' => true,
                    'amount' => (float) $classAllocatedAmount,
                    'budget_source' => $classBudgetSource,
                    'children' => $class->types->map(function($type) use ($barangayId, $budgetId, $budgetIds, $budgetType) {
                        // Calculate allocated amount for this expense type
                        $typeQuery = TranAppropriation::where('barangay_id', $barangayId)
                            ->where('expense_type_id', $type->id)
                            ->where('status', 'committed');

                        if ($budgetId) {
                            $typeQuery->where('budget_id', $budgetId);
                        } elseif ($budgetType !== 'all' && !empty($budgetIds)) {
                            $typeQuery->whereIn('budget_id', $budgetIds);
                        }

                        $typeAllocatedAmount = $typeQuery->sum('amount');

                        // Get budget source information for expense type
                        $typeBudgetSource = 'Annual Budget'; // Default
                        if ($budgetType === 'annual') {
                            $typeBudgetSource = 'Annual Budget';
                        } elseif ($budgetType === 'supplemental') {
                            $typeBudgetSource = 'Supplemental Budget';
                        } else {
                            // For 'all', determine based on the budgets that have allocations
                            if ($budgetId) {
                                $budget = Budget::find($budgetId);
                                if ($budget && $budget->description) {
                                    $description = strtolower($budget->description);
                                    if (strpos($description, 'supplemental') !== false) {
                                        $typeBudgetSource = 'Supplemental Budget';
                                    } elseif (strpos($description, 'annual') !== false) {
                                        $typeBudgetSource = 'Annual Budget';
                                    }
                                }
                            } else {
                                // Check if there are multiple budgets with different sources
                                $budgets = Budget::whereHas('tranAppropriations', function($q) use ($barangayId, $type) {
                                    $q->where('barangay_id', $barangayId)
                                      ->where('expense_type_id', $type->id)
                                      ->where('status', 'committed');
                                })->get();
                                
                                if ($budgets->count() > 0) {
                                    $hasSupplemental = $budgets->filter(function($budget) {
                                        return strpos(strtolower($budget->description), 'supplemental') !== false;
                                    })->count() > 0;
                                    
                                    $hasAnnual = $budgets->filter(function($budget) {
                                        return strpos(strtolower($budget->description), 'annual') !== false;
                                    })->count() > 0;
                                    
                                    if ($hasSupplemental && $hasAnnual) {
                                        $typeBudgetSource = 'Mixed';
                                    } elseif ($hasSupplemental) {
                                        $typeBudgetSource = 'Supplemental Budget';
                                    } elseif ($hasAnnual) {
                                        $typeBudgetSource = 'Annual Budget';
                                    }
                                }
                            }
                        }

                        return [
                            'id' => $type->id,
                            'name' => $type->name,
                            'isMainCategory' => false,
                            'amount' => (float) $typeAllocatedAmount,
                            'budget_source' => $typeBudgetSource,
                            'children' => $type->items->map(function($item) use ($barangayId, $budgetId, $budgetIds, $budgetType) {
                                // Get the allocated amount for this expense item
                                $query = TranAppropriation::where('barangay_id', $barangayId)
                                    ->where('expense_item_id', $item->id)
                                    ->where('status', 'committed');

                                // If budget_id is provided, filter by that specific budget
                                if ($budgetId) {
                                    $query->where('budget_id', $budgetId);
                                } elseif ($budgetType !== 'all' && !empty($budgetIds)) {
                                    $query->whereIn('budget_id', $budgetIds);
                                }

                                $allocatedAmount = $query->sum('amount');

                                // Get budget source information from the budget description
                                $budgetSource = 'Annual Budget'; // Default
                                if ($budgetType === 'annual') {
                                    $budgetSource = 'Annual Budget';
                                } elseif ($budgetType === 'supplemental') {
                                    $budgetSource = 'Supplemental Budget';
                                } else {
                                    // For 'all', determine based on the budgets that have allocations
                                    if ($budgetId) {
                                        $budget = Budget::find($budgetId);
                                        if ($budget && $budget->description) {
                                            $description = strtolower($budget->description);
                                            if (strpos($description, 'supplemental') !== false) {
                                                $budgetSource = 'Supplemental Budget';
                                            } elseif (strpos($description, 'annual') !== false) {
                                                $budgetSource = 'Annual Budget';
                                            }
                                        }
                                    } else {
                                        // Check if there are multiple budgets with different sources
                                        $budgets = Budget::whereHas('tranAppropriations', function($q) use ($barangayId, $item) {
                                            $q->where('barangay_id', $barangayId)
                                              ->where('expense_item_id', $item->id)
                                              ->where('status', 'committed');
                                        })->get();

                                        if ($budgets->count() > 0) {
                                            $hasSupplemental = $budgets->filter(function($budget) {
                                                return strpos(strtolower($budget->description), 'supplemental') !== false;
                                            })->count() > 0;

                                            $hasAnnual = $budgets->filter(function($budget) {
                                                return strpos(strtolower($budget->description), 'annual') !== false;
                                            })->count() > 0;

                                            if ($hasSupplemental && $hasAnnual) {
                                                $budgetSource = 'Mixed';
                                            } elseif ($hasSupplemental) {
                                                $budgetSource = 'Supplemental Budget';
                                            } elseif ($hasAnnual) {
                                                $budgetSource = 'Annual Budget';
                                            }
                                        }
                                    }
                                }

                                return [
                                    'id' => $item->id,
                                    'name' => $item->name,
                                    'isMainCategory' => false,
                                    'amount' => (float) $allocatedAmount,
                                    'budget_source' => $budgetSource,
                                    'children' => $item->childItems->map(function($subItem) use ($barangayId, $budgetId, $budgetIds, $budgetType) {
                                        // Get the allocated amount for this expense sub-item
                                        $subQuery = TranAppropriation::where('barangay_id', $barangayId)
                                            ->where('expense_sub_item_id', $subItem->id)
                                            ->where('status', 'committed');

                                        // If budget_id is provided, filter by that specific budget
                                        if ($budgetId) {
                                            $subQuery->where('budget_id', $budgetId);
                                        } elseif ($budgetType !== 'all' && !empty($budgetIds)) {
                                            $subQuery->whereIn('budget_id', $budgetIds);
                                        }

                                        $subAllocatedAmount = $subQuery->sum('amount');

                                        // Get budget source information from the budget description
                                        $subBudgetSource = 'Annual Budget'; // Default
                                        if ($budgetType === 'annual') {
                                            $subBudgetSource = 'Annual Budget';
                                        } elseif ($budgetType === 'supplemental') {
                                            $subBudgetSource = 'Supplemental Budget';
                                        } else {
                                            // For 'all', determine based on the budgets that have allocations
                                            if ($budgetId) {
                                                $budget = Budget::find($budgetId);
                                                if ($budget && $budget->description) {
                                                    $description = strtolower($budget->description);
                                                    if (strpos($description, 'supplemental') !== false) {
                                                        $subBudgetSource = 'Supplemental Budget';
                                                    } elseif (strpos($description, 'annual') !== false) {
                                                        $subBudgetSource = 'Annual Budget';
                                                    }
                                                }
                                            } else {
                                                // Check if there are multiple budgets with different sources
                                                $subBudgets = Budget::whereHas('tranAppropriations', function($q) use ($barangayId, $subItem) {
                                                    $q->where('barangay_id', $barangayId)
                                                      ->where('expense_sub_item_id', $subItem->id)
                                                      ->where('status', 'committed');
                                                })->get();

                                                if ($subBudgets->count() > 0) {
                                                    $hasSupplemental = $subBudgets->filter(function($budget) {
                                                        return strpos(strtolower($budget->description), 'supplemental') !== false;
                                                    })->count() > 0;

                                                    $hasAnnual = $subBudgets->filter(function($budget) {
                                                        return strpos(strtolower($budget->description), 'annual') !== false;
                                                    })->count() > 0;

                                                    if ($hasSupplemental && $hasAnnual) {
                                                        $subBudgetSource = 'Mixed';
                                                    } elseif ($hasSupplemental) {
                                                        $subBudgetSource = 'Supplemental Budget';
                                                    } elseif ($hasAnnual) {
                                                        $subBudgetSource = 'Annual Budget';
                                                    }
                                                }
                                            }
                                        }

                                        return [
                                            'id' => $subItem->id,
                                            'name' => $subItem->name,
                                            'isMainCategory' => false,
                                            'amount' => (float) $subAllocatedAmount,
                                            'budget_source' => $subBudgetSource
                                        ];
                                    })
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
         'allocations.*.type' => 'required|in:class,type,item,sub-item',
         'allocations.*.amount' => 'required|numeric|min:0',
         'allocations.*.expense_class_id' => 'nullable|integer|exists:lib_expense_classes,id',
         'allocations.*.expense_type_id' => 'nullable|integer|exists:lib_expense_types,id',
         'allocations.*.expense_item_id' => 'nullable|integer|exists:lib_expense_items,id',
         'allocations.*.expense_sub_item_id' => 'nullable|integer|exists:lib_expense_items,id'
     ]);

     \Log::info('SaveAllocation called with data:', [
         'budget_id' => $budget->id,
         'allocations_count' => count($validated['allocations']),
         'allocations' => $validated['allocations']
     ]);

    // Get existing allocations for this budget
    $existingAllocations = TranAppropriation::where('budget_id', $budget->id)
        ->where('barangay_id', $request->user()->barangay_id)
        ->get();

    // Calculate existing total using lowest level logic
    $existingLowestLevel = [];
    $existingProcessedItems = [];
    $existingProcessedTypes = [];
    $existingProcessedClasses = [];

    foreach ($existingAllocations as $existing) {
        if ($existing->expense_sub_item_id) {
            $existingLowestLevel[] = $existing->amount;
            $existingProcessedItems[] = $existing->expense_item_id;
        } elseif ($existing->expense_item_id && !in_array($existing->expense_item_id, $existingProcessedItems)) {
            $existingLowestLevel[] = $existing->amount;
            $existingProcessedTypes[] = $existing->expense_type_id;
        } elseif ($existing->expense_type_id && !in_array($existing->expense_type_id, $existingProcessedTypes)) {
            $existingLowestLevel[] = $existing->amount;
            $existingProcessedClasses[] = $existing->expense_class_id;
        } elseif ($existing->expense_class_id && !in_array($existing->expense_class_id, $existingProcessedClasses)) {
            $existingLowestLevel[] = $existing->amount;
        }
    }

    $existingTotal = array_sum($existingLowestLevel);

    \Log::info('Existing allocations before processing:', [
        'existing_count' => $existingAllocations->count(),
        'existing_total' => $existingTotal,
        'existing_allocations' => $existingAllocations->map(function($alloc) {
            return [
                'id' => $alloc->id,
                'amount' => $alloc->amount,
                'expense_class_id' => $alloc->expense_class_id,
                'expense_type_id' => $alloc->expense_type_id,
                'expense_item_id' => $alloc->expense_item_id,
                'expense_sub_item_id' => $alloc->expense_sub_item_id,
                'key' => $this->getAllocationKey($alloc)
            ];
        })->toArray()
    ]);

    // NEW: Validate that new appropriation amounts are not less than what has already been disbursed
    foreach ($validated['allocations'] as $allocation) {
        // Find the existing allocation to compare amounts
        $existingAllocation = $existingAllocations->first(function($existing) use ($allocation) {
            return $existing->expense_class_id == ($allocation['expense_class_id'] ?? null) &&
                   $existing->expense_type_id == ($allocation['expense_type_id'] ?? null) &&
                   $existing->expense_item_id == ($allocation['expense_item_id'] ?? null) &&
                   $existing->expense_sub_item_id == ($allocation['expense_sub_item_id'] ?? null);
        });

        if ($existingAllocation) {
            // Calculate how much has been disbursed from this appropriation using TranExpenseDetail
            $disbursedAmount = \App\Models\TranExpenseDetail::where('appropriation_id', $existingAllocation->id)
                ->sum('amount');

            // Check if the new amount is less than what has been disbursed
            if ($allocation['amount'] < $disbursedAmount) {
                
                return response()->json([
                    'status' => false,
                    'message' => sprintf(
                        'Cannot reduce appropriation below the disbursed amount. New amount must be at least ₱%s.',
                        number_format($disbursedAmount, 2)
                    )
                ], 422);
            }
        }
    }

    // Calculate new total using lowest level logic
    $newLowestLevel = [];
    $newProcessedItems = [];
    $newProcessedTypes = [];
    $newProcessedClasses = [];

    foreach ($validated['allocations'] as $allocation) {
        if ($allocation['expense_sub_item_id']) {
            $newLowestLevel[] = $allocation['amount'];
            $newProcessedItems[] = $allocation['expense_item_id'];
        } elseif ($allocation['expense_item_id'] && !in_array($allocation['expense_item_id'], $newProcessedItems)) {
            $newLowestLevel[] = $allocation['amount'];
            $newProcessedTypes[] = $allocation['expense_type_id'];
        } elseif ($allocation['expense_type_id'] && !in_array($allocation['expense_type_id'], $newProcessedTypes)) {
            $newLowestLevel[] = $allocation['amount'];
            $newProcessedClasses[] = $allocation['expense_class_id'];
        } elseif ($allocation['expense_class_id'] && !in_array($allocation['expense_class_id'], $newProcessedClasses)) {
            $newLowestLevel[] = $allocation['amount'];
        }
    }

    $newTotal = array_sum($newLowestLevel);

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

        // Instead of deleting all existing allocations, update them intelligently
        // This preserves the TranExpenseDetail records that are linked to appropriations
        
        // Create a map of existing allocations for easy lookup
        $existingAllocationMap = [];
        foreach ($existingAllocations as $existing) {
            $key = $this->getAllocationKey($existing);
            $existingAllocationMap[$key] = $existing;
        }

        // Process new allocations
        foreach ($validated['allocations'] as $allocation) {
            $allocationKey = $this->getAllocationKey($allocation);
            
            \Log::info('Processing allocation:', [
                'allocation' => $allocation,
                'allocation_key' => $allocationKey,
                'exists_in_map' => isset($existingAllocationMap[$allocationKey])
            ]);
            
            if (isset($existingAllocationMap[$allocationKey])) {
                // Update existing allocation
                $existingAllocation = $existingAllocationMap[$allocationKey];
                $previousAmount = (float) $existingAllocation->amount;
                $existingAllocation->update([
                    'amount' => $allocation['amount'],
                    'transaction_date' => now(),
                    'status' => 'committed',
                    'user_id' => $request->user()->id,
                ]);
                $appropriations[] = $existingAllocation;
                
                // Remove from map to track which ones were updated
                unset($existingAllocationMap[$allocationKey]);

                \Log::info('Updated existing allocation:', [
                    'allocation_id' => $existingAllocation->id,
                    'previous_amount' => $previousAmount,
                    'new_amount' => $allocation['amount']
                ]);

                // Log edited allocation with previous vs new amount only if amount actually changed
                if (abs($previousAmount - $allocation['amount']) > 0.01) { // Use small threshold for float comparison
                    $identifier = $this->getExpenseIdentifier($allocation);
                    AdminAuthController::logUserAction(
                        $request->user(),
                        'Edited Allocation',
                        sprintf(
                            'Edited allocation %s: from ₱%s to ₱%s for budget "%s"',
                            $identifier,
                            number_format($previousAmount, 2),
                            number_format($allocation['amount'], 2),
                            $budget->description
                        )
                    );
                }
            } else {
                // Create new allocation
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

                $newAllocation = TranAppropriation::create($appropriationData);
                $appropriations[] = $newAllocation;

                \Log::info('Created new allocation:', [
                    'allocation_id' => $newAllocation->id,
                    'amount' => $allocation['amount'],
                    'expense_sub_item_id' => $allocation['expense_sub_item_id'] ?? null
                ]);

                // Log committed allocation
                $identifier = $this->getExpenseIdentifier($allocation);
                AdminAuthController::logUserAction(
                    $request->user(),
                    'Committed Allocation',
                    sprintf(
                        'Committed allocation %s: ₱%s for budget "%s"',
                        $identifier,
                        number_format($allocation['amount'], 2),
                        $budget->description
                    )
                );
            }
        }

        // Collect item IDs that have sub-item allocations
        $itemsWithSubAllocations = [];
        foreach ($validated['allocations'] as $allocation) {
            if ($allocation['expense_sub_item_id']) {
                $itemsWithSubAllocations[] = $allocation['expense_item_id'];
            }
        }

        // Delete only the allocations that are no longer needed
        // This preserves TranExpenseDetail records for allocations that still exist
        \Log::info('Allocations to be deleted:', [
            'count' => count($existingAllocationMap),
            'allocations' => array_map(function($alloc) {
                return [
                    'id' => $alloc->id,
                    'amount' => $alloc->amount,
                    'expense_class_id' => $alloc->expense_class_id,
                    'expense_type_id' => $alloc->expense_type_id,
                    'expense_item_id' => $alloc->expense_item_id,
                    'expense_sub_item_id' => $alloc->expense_sub_item_id,
                    'key' => $this->getAllocationKey($alloc)
                ];
            }, $existingAllocationMap)
        ]);

        foreach ($existingAllocationMap as $key => $existingAllocation) {
            // Don't delete item allocations if they have sub-item allocations
            if ($existingAllocation->expense_item_id && !$existingAllocation->expense_sub_item_id && in_array($existingAllocation->expense_item_id, $itemsWithSubAllocations)) {
                continue;
            }
            \Log::info('Deleting allocation:', [
                'allocation_id' => $existingAllocation->id,
                'amount' => $existingAllocation->amount,
                'expense_sub_item_id' => $existingAllocation->expense_sub_item_id
            ]);
            $existingAllocation->delete();
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

    /**
     * Helper method to get a human-readable identifier for an expense allocation
     */
    private function getExpenseIdentifier($allocation)
    {
        if (isset($allocation['expense_item_id'])) {
            $item = \App\Models\LibExpenseItem::find($allocation['expense_item_id']);
            if ($item) {
                $type = \App\Models\LibExpenseType::find($item->expense_type_id);
                $class = \App\Models\LibExpenseClass::find($item->expense_class_id);
                return sprintf('%s > %s > %s', 
                    $class ? $class->name : 'Unknown Class',
                    $type ? $type->name : 'Unknown Type',
                    $item->name
                );
            }
        } elseif (isset($allocation['expense_type_id'])) {
            $type = \App\Models\LibExpenseType::find($allocation['expense_type_id']);
            if ($type) {
                $class = \App\Models\LibExpenseClass::find($type->expense_class_id);
                return sprintf('%s > %s', 
                    $class ? $class->name : 'Unknown Class',
                    $type->name
                );
            }
        } elseif (isset($allocation['expense_class_id'])) {
            $class = \App\Models\LibExpenseClass::find($allocation['expense_class_id']);
            return $class ? $class->name : 'Unknown Class';
        }
        
        return 'Unknown Expense Account';
    }

    /**
     * Helper method to generate a unique key for an allocation
     */
    private function getAllocationKey($allocation)
    {
        // For arrays (from request)
        if (is_array($allocation)) {
            return sprintf(
                'class_%s_type_%s_item_%s_subitem_%s',
                $allocation['expense_class_id'] ?? 'null',
                $allocation['expense_type_id'] ?? 'null',
                $allocation['expense_item_id'] ?? 'null',
                $allocation['expense_sub_item_id'] ?? 'null'
            );
        }

        // For models (from database)
        return sprintf(
            'class_%s_type_%s_item_%s_subitem_%s',
            $allocation->expense_class_id ?? 'null',
            $allocation->expense_type_id ?? 'null',
            $allocation->expense_item_id ?? 'null',
            $allocation->expense_sub_item_id ?? 'null'
        );
    }

    // In your AppropriationController.php
    public function getBudgetAllocations($budgetId)
    {
        $allocations = TranAppropriation::with(['expenseClass', 'expenseType', 'expenseItem', 'expenseSubItem'])
            ->where('budget_id', $budgetId)
            ->get()
            ->map(function($alloc) {
                return [
                    'id' => $alloc->id,
                    'expense_item_id' => $alloc->expense_item_id,
                    'expense_type_id' => $alloc->expense_type_id,
                    'expense_class_id' => $alloc->expense_class_id,
                    'expense_sub_item_id' => $alloc->expense_sub_item_id,
                    'expense_class_name' => $alloc->expenseClass->name ?? null,
                    'expense_type_name' => $alloc->expenseType->name ?? null,
                    'expense_item_name' => $alloc->expenseItem->name ?? null,
                    'expense_sub_item_name' => $alloc->expenseSubItem->name ?? null,
                    'amount' => (float)$alloc->amount
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $allocations
        ]);
    }

    // Rest of the methods remain the same...
    public function adjustBudget(Request $request, Budget $budget)
    {
        $validated = $request->validate([
            'type' => 'required|in:augmentation,return',
            'amount' => 'required|numeric|min:0',
            'reason' => 'required|string|max:255'
        ]);

        return DB::transaction(function () use ($validated, $budget) {
            if ($validated['type'] === 'augmentation') {
                $budget->increment('augmentation', $validated['amount']);
                $budget->increment('current_amount', $validated['amount']);
            } else {
                $budget->increment('return_amount', $validated['amount']);
                $budget->decrement('current_amount', $validated['amount']);
            }

            return response()->json($budget);
        });
    }

    public function getAllocationHistory($budgetId)
    {
        try {
            // Get the budget with all related appropriations
            $budget = Budget::with([
                'tranAppropriations' => function($query) {
                    $query->with([
                        'expenseClass:id,name',
                        'expenseType:id,name,expense_class_id',
                        'expenseItem:id,name,expense_type_id',
                        'expenseSubItem:id,name,parent_item_id'
                    ])->orderBy('created_at', 'desc');
                },
                'fiscalYear'
            ])->findOrFail($budgetId);

            // Use all appropriations (items, types, and classes)
            $allAppropriations = $budget->tranAppropriations;

            // Group by allocation date (session)
            $groupedHistory = $allAppropriations->groupBy(function($item) {
                return $item->created_at->format('Y-m-d H:i:s');
            });

            // Format response with ALL historical records
            $history = $groupedHistory->map(function($allocations, $date) use ($budget) {
                return [
                    'date' => $date,
                    'created_at' => $allocations->first()->created_at,
                    'total_allocated' => $allocations->sum('amount'),
                    'allocations' => $allocations->map(function($alloc) {
                        return [
                            'id' => $alloc->id,
                            'amount' => (float)$alloc->amount,
                            'expense_class_id' => $alloc->expense_class_id,
                            'expense_class_name' => $alloc->expenseClass->name ?? null,
                            'expense_type_id' => $alloc->expense_type_id,
                            'expense_type_name' => $alloc->expenseType->name ?? null,
                            'expense_item_id' => $alloc->expense_item_id,
                            'expense_item_name' => $alloc->expenseItem->name ?? null,
                            'expense_sub_item_id' => $alloc->expense_sub_item_id,
                            'expense_sub_item_name' => $alloc->expenseSubItem->name ?? null,
                        ];
                    }),
                    'remaining_unappropriated' => $budget->current_amount
                ];
            })->values();

            return response()->json([
                'status' => true,
                'data' => [
                    'budget' => $budget->only(['id', 'description', 'original_amount', 'current_amount']),
                    'fiscal_year' => $budget->fiscalYear->year ?? null,
                    'history' => $history,
                    'total_allocated_to_date' => $allAppropriations->sum('amount')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch allocation history: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateAllocations(Request $request, $budgetId)
    {
        $validated = $request->validate([
            'allocations' => 'required|array',
            'allocations.*.expense_item_id' => 'required|integer|exists:lib_expense_items,id',
            'allocations.*.amount' => 'required|numeric|min:0',
            'allocations.*.expense_class_id' => 'nullable|integer|exists:lib_expense_classes,id',
            'allocations.*.expense_type_id' => 'nullable|integer|exists:lib_expense_types,id',
        ]);

        $budget = \App\Models\Budget::findOrFail($budgetId);

        // NEW: Validate that new appropriation amounts are not less than what has already been disbursed
        foreach ($validated['allocations'] as $allocation) {
            // Find the existing allocation to compare amounts
            $existingAllocation = TranAppropriation::where('budget_id', $budgetId)
                ->where('barangay_id', $request->user()->barangay_id)
                ->where('expense_item_id', $allocation['expense_item_id'])
                ->first();

            if ($existingAllocation) {
                // Calculate how much has been disbursed from this appropriation
                $disbursedAmount = \App\Models\TranExpenseDetail::where('appropriation_id', $existingAllocation->id)
                    ->sum('amount');

                // Check if the new amount is less than what has been disbursed
                if ($allocation['amount'] < $disbursedAmount) {
                    $expenseIdentifier = $this->getExpenseIdentifier($allocation);
                    
                    return response()->json([
                        'status' => false,
                        'message' => sprintf(
                            'Cannot reduce appropriation for %s below ₱%s because ₱%s has already been disbursed. New amount must be at least ₱%s.',
                            $expenseIdentifier,
                            number_format($allocation['amount'], 2),
                            number_format($disbursedAmount, 2),
                            number_format($disbursedAmount, 2)
                        )
                    ], 422);
                }
            }
        }

        // FIXED: Check against current_amount instead of original_amount
        $totalAllocated = array_sum(array_column($validated['allocations'], 'amount'));
        if ($totalAllocated > $budget->current_amount) {
            return response()->json([
                'status' => false,
                'message' => sprintf(
                    'Allocation exceeds available budget by ₱%s. Available: ₱%s, Requested: ₱%s',
                    number_format($totalAllocated - $budget->current_amount, 2),
                    number_format($budget->current_amount, 2),
                    number_format($totalAllocated, 2)
                )
            ], 422);
        }

        return DB::transaction(function () use ($validated, $budget, $totalAllocated, $request) {
            // Get existing item-level appropriations for this budget
            $existingAllocations = $budget->tranAppropriations()
                ->whereNotNull('expense_item_id')
                ->get();

            // Create a map of existing allocations for easy lookup
            $existingAllocationMap = [];
            foreach ($existingAllocations as $existing) {
                $key = $this->getAllocationKey($existing);
                $existingAllocationMap[$key] = $existing;
            }

            // Process new allocations
            foreach ($validated['allocations'] as $alloc) {
                $allocationKey = $this->getAllocationKey($alloc);
                
                if (isset($existingAllocationMap[$allocationKey])) {
                    // Update existing allocation
                    $existingAllocation = $existingAllocationMap[$allocationKey];
                    $previousAmount = (float) $existingAllocation->amount;
                    $existingAllocation->update([
                        'amount' => $alloc['amount'],
                        'transaction_date' => now(),
                        'status' => 'committed',
                        'user_id' => $budget->user_id,
                    ]);
                    // Log edited allocation only if amount actually changed
                    if (abs($previousAmount - $alloc['amount']) > 0.01) { // Use small threshold for float comparison
                        $identifier = $this->getExpenseIdentifier($alloc);
                        AdminAuthController::logUserAction(
                            $request->user(),
                            'Edited Allocation',
                            sprintf(
                                'Edited allocation %s: from ₱%s to ₱%s for budget "%s"',
                                $identifier,
                                number_format($previousAmount, 2),
                                number_format($alloc['amount'], 2),
                                $budget->description
                            )
                        );
                    }
                    
                    // Remove from map to track which ones were updated
                    unset($existingAllocationMap[$allocationKey]);
                } else {
                    // Create new allocation
                    $budget->tranAppropriations()->create([
                        'barangay_id' => $budget->barangay_id,
                        'amount' => $alloc['amount'],
                        'expense_class_id' => $alloc['expense_class_id'] ?? null,
                        'expense_type_id' => $alloc['expense_type_id'] ?? null,
                        'expense_item_id' => $alloc['expense_item_id'],
                        'transaction_date' => now(),
                        'status' => 'committed',
                        'user_id' => $budget->user_id,
                    ]);
                    // Log committed allocation
                    $identifier = $this->getExpenseIdentifier($alloc);
                    AdminAuthController::logUserAction(
                        $request->user(),
                        'Committed Allocation',
                        sprintf(
                            'Committed allocation %s: ₱%s for budget "%s"',
                            $identifier,
                            number_format($alloc['amount'], 2),
                            $budget->description
                        )
                    );
                }
            }

            // Delete only the allocations that are no longer needed
            // This preserves TranExpenseDetail records for allocations that still exist
            foreach ($existingAllocationMap as $existingAllocation) {
                $existingAllocation->delete();
            }

            // Update current_amount by subtracting the total allocated
            $budget->current_amount = $budget->current_amount - $totalAllocated;
            $budget->save();

            return response()->json([
                'status' => true,
                'message' => 'Allocations updated',
                'budget' => $budget->fresh()
            ]);
        });
    }

    public function getDashboardSummary(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                \Log::error('Dashboard summary: No authenticated user');
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            \Log::info('Dashboard summary requested for user: ' . $user->id);

            $barangayId = $user->barangay_id;
            $year = $request->input('year', now()->year); // Default to current year if not specified
            
            \Log::info('Barangay ID: ' . $barangayId . ', Year: ' . $year);


            // Get budgets for this barangay with year filter - include both annual and supplemental budgets for obligation calculation
            $budgetsQuery = Budget::with(['tranAppropriations', 'fiscalYear'])
                ->where('barangay_id', $barangayId);
            
            // Get only annual budgets for total appropriation calculation
            $annualBudgetsQuery = Budget::with(['tranAppropriations', 'fiscalYear'])
                ->where('barangay_id', $barangayId)
                ->where('budget_type', \App\BudgetType::ANNUAL);
            
            if ($year !== 'all') {
                $budgetsQuery->whereHas('fiscalYear', function($q) use ($year) {
                    $q->where('year', $year);
                });
                $annualBudgetsQuery->whereHas('fiscalYear', function($q) use ($year) {
                    $q->where('year', $year);
                });
            }
            
            $budgets = $budgetsQuery->get(); // All budgets for obligation calculation
            $annualBudgets = $annualBudgetsQuery->get(); // Only annual budgets for appropriation calculation


            \Log::info('Found ' . $budgets->count() . ' budgets for year ' . $year);

            // Calculate totals - use annual budgets only for appropriation, all budgets for obligation
            $totalAppropriation = $annualBudgets->sum(function($budget) {
                return (float)$budget->original_amount + (float)$budget->augmentation;
            });
            $totalObligation = $budgets->sum(function($budget) {
                $total = 0;
                $processedItems = [];
                $processedTypes = [];
                $processedClasses = [];
                foreach ($budget->tranAppropriations as $appropriation) {
                    if ($appropriation->expense_sub_item_id) {
                        if (!in_array($appropriation->expense_item_id, $processedItems)) {
                            $processedItems[] = $appropriation->expense_item_id;
                        }
                        $total += $appropriation->amount;
                    } elseif ($appropriation->expense_item_id && !in_array($appropriation->expense_item_id, $processedItems)) {
                        $processedItems[] = $appropriation->expense_item_id;
                        if (!in_array($appropriation->expense_type_id, $processedTypes)) {
                            $processedTypes[] = $appropriation->expense_type_id;
                        }
                        $total += $appropriation->amount;
                    } elseif ($appropriation->expense_type_id && !in_array($appropriation->expense_type_id, $processedTypes)) {
                        $processedTypes[] = $appropriation->expense_type_id;
                        if (!in_array($appropriation->expense_class_id, $processedClasses)) {
                            $processedClasses[] = $appropriation->expense_class_id;
                        }
                        $total += $appropriation->amount;
                    } elseif ($appropriation->expense_class_id && !in_array($appropriation->expense_class_id, $processedClasses)) {
                        $processedClasses[] = $appropriation->expense_class_id;
                        $total += $appropriation->amount;
                    }
                }
                return $total;
            });
            $totals = TranAppropriation::select(
                            'tran_appropriations.expense_class_id',
                            'tran_appropriations.expense_type_id',
                            'tran_appropriations.expense_item_id',
                            DB::raw('SUM(tran_appropriations.amount) as total_amount')
                        )
                        ->where('tran_appropriations.barangay_id', $request->user()->barangay_id)
                        ->whereRelation('expenseClass.fiscalYear', 'year', '=', $year)
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
                        )
                        ->get();

            $details = TranAppropriation::select(
                            'tran_appropriations.expense_class_id',
                            'tran_appropriations.expense_type_id',
                            'tran_appropriations.expense_item_id',
                            DB::raw('SUM(ISNULL(tran_expense_details.amount,0)) as details_amount')
                        )
                        ->leftJoin('tran_expense_details', 'tran_expense_details.appropriation_id', '=', 'tran_appropriations.id')
                        ->where('tran_appropriations.barangay_id', $request->user()->barangay_id)
                        ->whereRelation('expenseClass.fiscalYear', 'year', '=', $year)
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
                        )
                        ->get();


            $totalBalance = $totals->sum(function ($o) use ($details) {
                $d = $details->first(fn($d) =>
                    $d->expense_class_id == $o->expense_class_id &&
                    $d->expense_type_id == $o->expense_type_id &&
                    $d->expense_item_id == $o->expense_item_id
                );

                return (float) $o->total_amount - (float) ($d->details_amount ?? 0);
            });
            $totalUnappropriated = $totalAppropriation - $totalObligation;
            $totalExpense = $totalObligation - $totalBalance;

            \Log::info('Totals - Appropriation: ' . $totalAppropriation . ', Obligation: ' . $totalObligation . ', Balance: ' . $totalBalance . ', Expense: ' . $totalExpense);


            // Get expense hierarchy for pie chart
            $fiscalYear = null;
            if ($year !== 'all') {
                $fiscalYear = LibFiscalYear::where('barangay_id', $barangayId)
                    ->where('year', $year)
                    ->first();
                \Log::info('Looking for fiscal year ' . $year . ' for barangay ' . $barangayId . ': ' . ($fiscalYear ? 'found' : 'not found'));
            } else {
                // For "all years", get the most recent fiscal year for structure reference
                $fiscalYear = LibFiscalYear::where('barangay_id', $barangayId)
                    ->orderBy('year', 'desc')
                    ->first();
                \Log::info('Looking for most recent fiscal year for barangay ' . $barangayId . ': ' . ($fiscalYear ? 'found year ' . $fiscalYear->year : 'not found'));
            }

            \Log::info('Fiscal year found: ' . ($fiscalYear ? 'yes' : 'no'));
            if ($fiscalYear) {
                \Log::info('Fiscal year details - ID: ' . $fiscalYear->id . ', Year: ' . $fiscalYear->year . ', Barangay: ' . $fiscalYear->barangay_id);
            }

            $expenseHierarchy = [];
            if ($fiscalYear) {
                $expenseHierarchyQuery = LibExpenseClass::with(['types.items'])
                    ->where('fiscal_year_id', $fiscalYear->id);
                
                $expenseHierarchy = $expenseHierarchyQuery->get()
                    ->map(function($class) {
                        return [
                            'id' => $class->id,
                            'name' => $class->name,
                            'children' => $class->types->map(function($type) {
                                return [
                                    'id' => $type->id,
                                    'name' => $type->name,
                                    'children' => $type->items->map(function($item) {
                                        return [
                                            'id' => $item->id,
                                            'name' => $item->name,
                                        ];
                                    })
                                ];
                            })
                        ];
                    });
                
                \Log::info('Found ' . $expenseHierarchy->count() . ' expense classes for fiscal year ' . $fiscalYear->id);
                foreach ($expenseHierarchy as $class) {
                    \Log::info('Expense class: ' . $class['name'] . ' (ID: ' . $class['id'] . ') with ' . count($class['children']) . ' types');
                }
            } else {
                \Log::warning('No fiscal year found, cannot get expense hierarchy');
                // Create a basic expense hierarchy structure for the pie chart
                $expenseHierarchy = [
                    [
                        'id' => 0,
                        'name' => 'No Expense Classes',
                        'children' => []
                    ]
                ];
            }

            \Log::info('Expense hierarchy count: ' . count($expenseHierarchy));

            // Calculate class totals for pie chart
            $classTotals = [];
            foreach ($expenseHierarchy as $expenseClass) {
                $classTotal = 0;
                foreach ($budgets as $budget) {
                    // Sum all allocations for this class (type-level and item-level)
                    $classTotal += $budget->tranAppropriations
                        ->where('expense_class_id', $expenseClass['id'])
                        ->sum('amount');
                }
                if ($classTotal > 0) {
                    $classTotals[] = [
                        'id' => $expenseClass['id'],
                        'name' => $expenseClass['name'],
                        'total' => $classTotal
                    ];
                    \Log::info('Class ' . $expenseClass['name'] . ' has total allocation: ' . $classTotal);
                } else {
                    \Log::info('Class ' . $expenseClass['name'] . ' has no allocations');
                }
            }

            \Log::info('Class totals count: ' . count($classTotals));
            
            // If no class totals found, create a placeholder
            if (empty($classTotals)) {
                \Log::info('No class totals found, creating placeholder data');
                $classTotals = [
                    [
                        'id' => 0,
                        'name' => 'No Allocations',
                        'total' => 1
                    ]
                ];
            }

            // Get top expense classes by allocation amount
            $topExpenseClasses = collect($classTotals)
                ->sortByDesc('total')
                ->take(5)
                ->values()
                ->all();

            // Get recent allocations (last 10)
            $recentAllocations = TranAppropriation::with(['expenseClass', 'expenseType', 'expenseItem'])
                ->where('barangay_id', $barangayId)
                ->where('status', 'committed')
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get()
                ->map(function($alloc) {
                    return [
                        'id' => $alloc->id,
                        'amount' => (float)$alloc->amount,
                        'expense_class_name' => $alloc->expenseClass->name ?? 'Unknown',
                        'expense_type_name' => $alloc->expenseType->name ?? 'Unknown',
                        'expense_item_name' => $alloc->expenseItem->name ?? null,
                        'allocation_type' => $alloc->expense_item_id ? 'Item' : 'Type',
                        'created_at' => $alloc->created_at->format('Y-m-d H:i:s'),
                    ];
                });

            // Calculate additional breakdown statistics
            $typeLevelTotal = $budgets->sum(function($budget) {
                return $budget->tranAppropriations
                    ->whereNotNull('expense_type_id')
                    ->whereNull('expense_item_id')
                    ->sum('amount');
            });
            
            $itemLevelTotal = $budgets->sum(function($budget) {
                return $budget->tranAppropriations
                    ->whereNotNull('expense_item_id')
                    ->sum('amount');
            });

            // Calculate additional statistics
            $totalAllocations = $budgets->sum(function($budget) {
                return $budget->tranAppropriations->count();
            });
            
            $typeLevelAllocations = $budgets->sum(function($budget) {
                return $budget->tranAppropriations
                    ->whereNotNull('expense_type_id')
                    ->whereNull('expense_item_id')
                    ->count();
            });
            
            $itemLevelAllocations = $budgets->sum(function($budget) {
                return $budget->tranAppropriations
                    ->whereNotNull('expense_item_id')
                    ->count();
            });

            $averageAllocation = $totalAllocations > 0 ? $totalObligation / $totalAllocations : 0;

            $response = [
                'status' => true,
                'data' => [
                    'summary' => [
                        'total_appropriation' => (float)$totalAppropriation,
                        'total_obligation' => (float)$totalObligation,
                        'total_expense' => (float)$totalExpense,
                        'total_unappropriated' => (float)$totalUnappropriated,
                        'total_balance' => (float)$totalBalance,
                        'type_level_total' => (float)$typeLevelTotal,
                        'item_level_total' => (float)$itemLevelTotal,
                        'total_allocations' => (int)$totalAllocations,
                        'type_level_allocations' => (int)$typeLevelAllocations,
                        'item_level_allocations' => (int)$itemLevelAllocations,
                        'average_allocation' => (float)$averageAllocation,
                    ],
                    'pie_chart_data' => [
                        'labels' => array_column($classTotals, 'name'),
                        'data' => array_column($classTotals, 'total'),
                    ],
                    'top_expense_classes' => $topExpenseClasses,
                    'recent_allocations' => $recentAllocations,
                    'budgets_count' => $budgets->count(),
                    'selected_year' => $year,
                    'fiscal_year_id' => $fiscalYear ? $fiscalYear->id : null,
                    'fiscal_year_name' => $fiscalYear ? $fiscalYear->year : null,
                ]
            ];

            \Log::info('Dashboard response prepared', $response);

            return response()->json($response);

        } catch (\Exception $e) {
            \Log::error('Dashboard summary error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Error fetching dashboard data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug endpoint to troubleshoot dashboard issues
     */
    public function getDashboardDebug(Request $request)
    {
        try {
        $user = $request->user();
        if (!$user) {
            \Log::error('Dashboard summary: No authenticated user');
            return response()->json([
                'status' => false,
                'message' => 'User not authenticated'
            ], 401);
        }
        $barangayId = $user->barangay_id;
            
            \Log::info('Dashboard debug requested for user: ' . $user->id . ', barangay: ' . $barangayId);
            
            // Check fiscal years
            $fiscalYears = LibFiscalYear::where('barangay_id', $barangayId)->get();
            \Log::info('Found ' . $fiscalYears->count() . ' fiscal years for barangay ' . $barangayId);
            
            // Check budgets
            $budgets = Budget::where('barangay_id', $barangayId)->get();
            \Log::info('Found ' . $budgets->count() . ' budgets for barangay ' . $barangayId);
            
            // Check expense classes
            $expenseClasses = LibExpenseClass::where('barangay_id', $barangayId)->orderBy('order')->get();
            \Log::info('Found ' . $expenseClasses->count() . ' expense classes for barangay ' . $barangayId);
            
            // Check appropriations
            $appropriations = TranAppropriation::where('barangay_id', $barangayId)->get();
            \Log::info('Found ' . $appropriations->count() . ' appropriations for barangay ' . $barangayId);
            
            // Check disbursements
            $disbursements = \App\Models\Disbursement::where('barangay_id', $barangayId)->get();
            \Log::info('Found ' . $disbursements->count() . ' disbursements for barangay ' . $barangayId);
            
            return response()->json([
                'status' => true,
                'data' => [
                    'barangay_id' => $barangayId,
                    'fiscal_years_count' => $fiscalYears->count(),
                    'budgets_count' => $budgets->count(),
                    'expense_classes_count' => $expenseClasses->count(),
                    'appropriations_count' => $appropriations->count(),
                    'disbursements_count' => $disbursements->count(),
                    'fiscal_years' => $fiscalYears->map(function($fy) {
                        return ['id' => $fy->id, 'year' => $fy->year, 'is_active' => $fy->is_active];
                    }),
                    'budgets' => $budgets->map(function($budget) {
                        return [
                            'id' => $budget->id,
                            'fiscal_year_id' => $budget->fiscal_year_id,
                            'original_amount' => $budget->original_amount,
                            'current_amount' => $budget->current_amount,
                            'description' => $budget->description
                        ];
                    }),
                    'expense_classes' => $expenseClasses->map(function($class) {
                        return [
                            'id' => $class->id,
                            'name' => $class->name,
                            'fiscal_year_id' => $class->fiscal_year_id
                        ];
                    }),
                    'appropriations' => $appropriations->map(function($appr) {
                        return [
                            'id' => $appr->id,
                            'amount' => $appr->amount,
                            'status' => $appr->status,
                            'expense_class_id' => $appr->expense_class_id,
                            'expense_type_id' => $appr->expense_type_id,
                            'expense_item_id' => $appr->expense_item_id
                        ];
                    })
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Dashboard debug error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Error in debug endpoint: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get appropriations for augmentation (returns appropriations grouped by expense hierarchy)
     */
    public function getAppropriationsForAugmentation(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:draft,committed,reverted',
            'fiscal_year_id' => 'nullable|exists:lib_fiscal_years,id',
            'budget_type' => 'nullable|in:annual,supplemental,all'
        ]);

        $barangayId = $request->user()->barangay_id;
        $status = $request->status ?? 'committed';
        $budgetType = $request->input('budget_type', 'all');

        $query = TranAppropriation::with(['expenseClass', 'expenseType', 'expenseItem', 'expenseSubItem', 'budget'])
            ->where('barangay_id', $barangayId)
            ->where('status', $status);

        if ($request->fiscal_year_id) {
            $query->whereHas('budget', function($q) use ($request) {
                $q->where('fiscal_year_id', $request->fiscal_year_id);
            });
        }

        // Add budget type filtering
        if ($budgetType !== 'all') {
            $query->whereHas('budget', function($q) use ($budgetType) {
                if ($budgetType === 'annual') {
                    $q->where('description', 'like', '%annual%');
                } elseif ($budgetType === 'supplemental') {
                    $q->where('description', 'like', '%supplemental%');
                }
            });
        }

        $appropriations = $query->get();
        
        // Debug logging
        \Log::info('Budget type filter: ' . $budgetType);
        \Log::info('Total appropriations found: ' . $appropriations->count());
        \Log::info('Appropriations with budget info:', $appropriations->map(function($app) {
            return [
                'id' => $app->id,
                'budget_id' => $app->budget_id,
                'budget_description' => $app->budget ? $app->budget->description : 'No budget',
                'amount' => $app->amount
            ];
        })->toArray());

        // Group appropriations by expense hierarchy (class, type, item)
        $groupedAppropriations = [];
        
        foreach ($appropriations as $appropriation) {
            // Create a unique key for grouping (including subitem)
            $key = $appropriation->expense_class_id . '_' . 
                   ($appropriation->expense_type_id ?? 'null') . '_' . 
                   ($appropriation->expense_item_id ?? 'null') . '_' .
                   ($appropriation->expense_sub_item_id ?? 'null');
            
            if (!isset($groupedAppropriations[$key])) {
                // Build account name including subitem
                $accountParts = [];
                if ($appropriation->expenseClass) {
                    $accountParts[] = $appropriation->expenseClass->name;
                }
                if ($appropriation->expenseType) {
                    $accountParts[] = $appropriation->expenseType->name;
                }
                if ($appropriation->expenseItem) {
                    $accountParts[] = $appropriation->expenseItem->name;
                }
                if ($appropriation->expenseSubItem) {
                    $accountParts[] = $appropriation->expenseSubItem->name;
                }
                
                $accountName = implode(' > ', $accountParts);

                // Get all appropriations with the same expense hierarchy (including subitem)
                $matchingAppropriations = $appropriations->filter(function($appr) use ($appropriation) {
                    return $appr->expense_class_id === $appropriation->expense_class_id &&
                           $appr->expense_type_id === $appropriation->expense_type_id &&
                           $appr->expense_item_id === $appropriation->expense_item_id &&
                           $appr->expense_sub_item_id === $appropriation->expense_sub_item_id;
                });

                // Calculate total amount and get the first appropriation ID for reference
                $totalAmount = $matchingAppropriations->sum('amount');
                $firstAppropriation = $matchingAppropriations->first();

                $groupedAppropriations[$key] = [
                    'id' => $firstAppropriation->id, // Use first appropriation ID as reference
                    'account_name' => $accountName,
                    'amount' => (float)$totalAmount,
                    'expense_class_id' => $appropriation->expense_class_id,
                    'expense_class_name' => $appropriation->expenseClass ? $appropriation->expenseClass->name : null,
                    'expense_type_id' => $appropriation->expense_type_id,
                    'expense_type_name' => $appropriation->expenseType ? $appropriation->expenseType->name : null,
                    'expense_item_id' => $appropriation->expense_item_id,
                    'expense_item_name' => $appropriation->expenseItem ? $appropriation->expenseItem->name : null,
                    'expense_sub_item_id' => $appropriation->expense_sub_item_id,
                    'expense_sub_item_name' => $appropriation->expenseSubItem ? $appropriation->expenseSubItem->name : null,
                    'budget_id' => $firstAppropriation->budget_id,
                    'budget_description' => $firstAppropriation->budget ? $firstAppropriation->budget->description : 'Unknown Budget',
                    'status' => $appropriation->status,
                    'created_at' => $firstAppropriation->created_at->format('Y-m-d'),
                    'appropriation_ids' => $matchingAppropriations->pluck('id')->toArray() // Store all IDs for reference
                ];
            }
        }

        // Convert to array and sort by appropriation ID
        $result = array_values($groupedAppropriations);
        usort($result, function($a, $b) {
            return $a['id'] - $b['id'];
        });

        return response()->json([
            'status' => true,
            'data' => $result
        ]);
    }

    /**
     * Get unused expenses for supplemental budget creation
     */
    public function getUnusedExpenses(Request $request)
    {
        $request->validate([
            'year' => 'nullable|integer',
            'barangay_id' => 'nullable|exists:barangays,id'
        ]);

        // Get base query for appropriations - exclude supplemental budget appropriations
        $query = TranAppropriation::with(['expenseClass', 'expenseType', 'expenseItem', 'expenseSubItem', 'budget.fiscalYear'])
            ->where('status', 'committed')
            ->whereDoesntHave('budget', function($q) {
                $q->where('description', 'like', '%supplemental%');
            });

        // Filter by barangay
        if ($request->barangay_id) {
            $query->where('barangay_id', $request->barangay_id);
        } else {
            $user = $request->user('barangay');
            if ($user) {
                $query->where('barangay_id', $user->barangay_id);
            }
        }

        // Filter by year
        if ($request->year) {
            $query->whereHas('budget.fiscalYear', function($q) use ($request) {
                $q->where('year', $request->year);
            });
        }

        $appropriations = $query->get();
        
        \Log::info('Filtered appropriations for unused expenses calculation:', [
            'total_appropriations' => $appropriations->count(),
            'appropriation_ids' => $appropriations->pluck('id')->toArray(),
            'budget_descriptions' => $appropriations->map(function($appr) {
                return $appr->budget ? $appr->budget->description : 'No Budget';
            })->unique()->values()->toArray(),
            'individual_amounts' => $appropriations->map(function($appr) {
                return [
                    'id' => $appr->id,
                    'amount' => $appr->amount,
                    'budget_description' => $appr->budget ? $appr->budget->description : 'No Budget'
                ];
            })->toArray()
        ]);

        // Group by expense hierarchy and calculate unused amounts
        $groupedExpenses = [];
        
        foreach ($appropriations as $appropriation) {
            $key = $appropriation->expense_class_id . '_' . 
                   ($appropriation->expense_type_id ?? 'null') . '_' . 
                   ($appropriation->expense_item_id ?? 'null') . '_' . 
                   ($appropriation->expense_sub_item_id ?? 'null');
            
            if (!isset($groupedExpenses[$key])) {
                // Build account name
                $accountParts = [];
                if ($appropriation->expenseClass) {
                    $accountParts[] = $appropriation->expenseClass->name;
                }
                if ($appropriation->expenseType) {
                    $accountParts[] = $appropriation->expenseType->name;
                }
                if ($appropriation->expenseItem) {
                    $accountParts[] = $appropriation->expenseItem->name;
                }
                if ($appropriation->expenseSubItem) {
                    $accountParts[] = $appropriation->expenseSubItem->name;
                }
                
                $accountName = implode(' > ', $accountParts);

                // Get all appropriations with the same expense hierarchy
                $matchingAppropriations = $appropriations->filter(function($appr) use ($appropriation) {
                    return $appr->expense_class_id === $appropriation->expense_class_id &&
                           $appr->expense_type_id === $appropriation->expense_type_id &&
                           $appr->expense_item_id === $appropriation->expense_item_id &&
                           $appr->expense_sub_item_id === $appropriation->expense_sub_item_id;
                });

                // Calculate total appropriated amount (current sum of appropriation amounts)
                $totalAppropriated = $matchingAppropriations->sum('amount');
                
                // Calculate total disbursed amount from expense details
                $totalDisbursed = 0;
                foreach ($matchingAppropriations as $appr) {
                    $totalDisbursed += $appr->details()->sum('amount');
                }

                // Calculate unused amount (current appropriation amount minus disbursed)
                // This correctly reflects the current state after any supplemental budget transfers
                $unusedAmount = $totalAppropriated - $totalDisbursed;

                \Log::info('Calculating unused amount for expense:', [
                    'account_name' => $accountName,
                    'total_appropriated' => $totalAppropriated,
                    'total_disbursed' => $totalDisbursed,
                    'unused_amount' => $unusedAmount,
                    'appropriation_ids' => $matchingAppropriations->pluck('id')->toArray(),
                    'individual_amounts' => $matchingAppropriations->pluck('amount')->toArray(),
                    'individual_disbursed' => $matchingAppropriations->map(function($appr) {
                        return $appr->details()->sum('amount');
                    })->toArray()
                ]);

                // Only include if there's unused amount
                if ($unusedAmount > 0) {
                    $groupedExpenses[$key] = [
                        'id' => $matchingAppropriations->first()->id,
                        'account_name' => $accountName,
                        'expense_class' => $appropriation->expenseClass->name ?? '',
                        'expense_type' => $appropriation->expenseType->name ?? '',
                        'expense_item' => $appropriation->expenseItem->name ?? '',
                        'expense_sub_item' => $appropriation->expenseSubItem->name ?? '',
                        'expense_class_id' => $appropriation->expense_class_id,
                        'expense_type_id' => $appropriation->expense_type_id,
                        'expense_item_id' => $appropriation->expense_item_id,
                        'expense_sub_item_id' => $appropriation->expense_sub_item_id,
                        'total_appropriated' => (float)$totalAppropriated,
                        'total_disbursed' => (float)$totalDisbursed,
                        'unused_amount' => (float)$unusedAmount,
                        'budget_description' => $matchingAppropriations->first()->budget->description ?? '',
                        'budget_type' => str_contains(strtolower($matchingAppropriations->first()->budget->description ?? ''), 'supplemental') ? 'supplemental' : 'annual'
                    ];
                }
            }
        }

        $totalUnused = collect($groupedExpenses)->sum('unused_amount');

        return response()->json([
            'status' => true,
            'data' => array_values($groupedExpenses),
            'total_unused' => $totalUnused
        ]);
    }

    /**
     * Create supplemental budget from unused expenses
     */
    public function createSupplementalBudget(Request $request)
    {
        \Log::info('Creating supplemental budget with data:', $request->all());

        try {
            $request->validate([
                'description' => 'required|string|max:255',
                'year' => 'required|integer',
                'expense_sources' => 'required|array|min:1',
                'expense_sources.*.appropriation_id' => 'required|exists:tran_appropriations,id',
                'expense_sources.*.amount' => 'required|numeric|min:0.01',
                'barangay_id' => 'nullable|exists:barangays,id'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation failed for createSupplementalBudget:', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in createSupplementalBudget:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'An unexpected error occurred: ' . $e->getMessage()
            ], 500);
        }

        return DB::transaction(function () use ($request) {
            try {
                // Determine barangay_id
                $barangayId = null;
                if ($request->barangay_id) {
                    $barangayId = $request->barangay_id;
                } else {
                    $user = $request->user('barangay');
                    $barangayId = $user->barangay_id;
                }

            // Get fiscal year ID from year
            $fiscalYear = LibFiscalYear::where('year', $request->year)
                ->where('barangay_id', $barangayId)
                ->first();
            
            if (!$fiscalYear) {
                throw new \Exception('Fiscal year not found for year: ' . $request->year);
            }

            // Calculate total amount
            $totalAmount = collect($request->expense_sources)->sum('amount');

            // Ensure description contains "supplemental" for proper identification
            $description = $request->description;
            if (!str_contains(strtolower($description), 'supplemental')) {
                $description = 'Supplemental Budget - ' . $description;
            }

            // Create supplemental budget
            $budget = Budget::create([
                'barangay_id' => $barangayId,
                'fiscal_year_id' => $fiscalYear->id,
                'start_date' => now()->startOfYear(),
                'end_date' => now()->endOfYear(),
                'description' => $description,
                'budget_type' => \App\BudgetType::SUPPLEMENTAL,
                'status' => 'draft',
                'effective_date' => now(),
                'original_amount' => $totalAmount,
                'current_amount' => $totalAmount,
                'augmentation' => 0,
                'return_amount' => 0,
                'user_id' => $request->user('barangay')->id ?? $request->user('admin')->id
            ]);

            // Create supplemental budget detail
            \App\Models\SupplementalBudgetDetail::create([
                'budget_id' => $budget->id,
                'barangay_id' => $barangayId,
                'supplement_type' => 'Additional Allocation',
                'source_of_supplement' => 'Reallocation from Unused Funds',
                'supplement_amount' => $totalAmount,
                'utilized_amount' => 0,
                'remaining_amount' => $totalAmount,
                'emergency_justification' => 'Transfer from unused funds to supplemental budget',
                'urgency_level' => 'medium',
                'impact_assessment' => 'Will provide additional funding for barangay operations',
                'request_date' => now(),
                'effective_date' => now(),
                'expiry_date' => now()->endOfYear(),
                'implementation_notes' => 'Created through fund transfer process',
                'supplement_status' => 'active',
                'supplement_approved_at' => now(),
                'supplement_approved_by' => $request->user('barangay')->id ?? $request->user('admin')->id
            ]);

            \Log::info('Created supplemental budget:', [
                'budget_id' => $budget->id,
                'description' => $budget->description,
                'total_amount' => $budget->original_amount,
                'fiscal_year_id' => $budget->fiscal_year_id,
                'budget_type' => $budget->budget_type->value
            ]);

            // Transfer funds from source appropriations to supplemental budget
            foreach ($request->expense_sources as $source) {
                $sourceAppropriation = TranAppropriation::find($source['appropriation_id']);
                
                if (!$sourceAppropriation) {
                    throw new \Exception('Source appropriation not found with ID: ' . $source['appropriation_id']);
                }

                // Check if source has enough amount
                if ($sourceAppropriation->amount < $source['amount']) {
                    throw new \Exception('Insufficient amount in source appropriation. Available: ' . $sourceAppropriation->amount . ', Requested: ' . $source['amount']);
                }

                // Log before reduction
                \Log::info('Before reducing source appropriation:', [
                    'source_id' => $sourceAppropriation->id,
                    'current_amount' => $sourceAppropriation->amount,
                    'amount_to_reduce' => $source['amount'],
                    'remaining_after' => $sourceAppropriation->amount - $source['amount']
                ]);

                // Reduce the source appropriation amount
                $sourceAppropriation->decrement('amount', $source['amount']);
                
                // Log after reduction
                \Log::info('After reducing source appropriation:', [
                    'source_id' => $sourceAppropriation->id,
                    'new_amount' => $sourceAppropriation->fresh()->amount,
                    'reduction_amount' => $source['amount']
                ]);

                \Log::info('Funds transferred to supplemental budget:', [
                    'source_id' => $sourceAppropriation->id,
                    'source_amount_before' => $sourceAppropriation->amount + $source['amount'],
                    'source_amount_after' => $sourceAppropriation->amount,
                    'transferred_amount' => $source['amount'],
                    'note' => 'Funds transferred to supplemental budget - no tran_appropriations entry created for supplemental budget'
                ]);
            }

            \Log::info('Transfer completed - funds moved to supplemental budget:', [
                'total_transferred' => $totalAmount,
                'supplemental_budget_id' => $budget->id,
                'note' => 'Funds transferred to supplemental budget - no tran_appropriations entries created for supplemental budget'
            ]);

            // Log the creation
            AdminAuthController::logUserAction(
                $request->user('barangay') ?? $request->user('admin'),
                'Created Supplemental Budget',
                sprintf(
                    'Created supplemental budget "%s" with amount ₱%s from %d expense sources',
                    $request->description,
                    number_format($totalAmount, 2),
                    count($request->expense_sources)
                )
            );

            \Log::info('Supplemental budget creation completed successfully:', [
                'budget_id' => $budget->id,
                'total_amount' => $totalAmount,
                'funds_transferred' => $totalAmount,
                'transaction_committed' => true
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Supplemental budget created successfully',
                'data' => [
                    'budget' => $budget,
                    'transferred_amount' => $totalAmount
                ]
            ], 201);
            } catch (\Exception $e) {
                \Log::error('Error in supplemental budget transaction:', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request_data' => $request->all()
                ]);
                throw $e; // Re-throw to trigger transaction rollback
            }
        });
    }

    /**
     * Get supplemental budgets
     */
    public function getSupplementalBudgets(Request $request)
    {
        $request->validate([
            'year' => 'nullable|integer',
            'barangay_id' => 'nullable|exists:barangays,id'
        ]);

        // Get base query for budgets with supplemental description
        $query = Budget::with(['tranAppropriations.expenseType', 'fiscalYear', 'barangay'])
            ->where('description', 'like', '%supplemental%');

        // Filter by barangay
        if ($request->barangay_id) {
            $query->where('barangay_id', $request->barangay_id);
        } else {
            $user = $request->user('barangay');
            if ($user) {
                $query->where('barangay_id', $user->barangay_id);
            }
        }

        // Filter by year
        if ($request->year) {
            $query->whereHas('fiscalYear', function($q) use ($request) {
                $q->where('year', $request->year);
            });
        }

        $budgets = $query->orderBy('created_at', 'desc')->get();

        $supplementalBudgets = $budgets->map(function($budget) {
            // Calculate total appropriated using lowest level logic
            $totalAppropriated = 0;
            $processedItems = [];
            $processedTypes = [];
            $processedClasses = [];

            foreach ($budget->tranAppropriations as $appropriation) {
                if ($appropriation->expense_sub_item_id) {
                    if (!in_array($appropriation->expense_item_id, $processedItems)) {
                        $processedItems[] = $appropriation->expense_item_id;
                    }
                    $totalAppropriated += $appropriation->amount;
                } elseif ($appropriation->expense_item_id && !in_array($appropriation->expense_item_id, $processedItems)) {
                    $processedItems[] = $appropriation->expense_item_id;
                    if (!in_array($appropriation->expense_type_id, $processedTypes)) {
                        $processedTypes[] = $appropriation->expense_type_id;
                    }
                    $totalAppropriated += $appropriation->amount;
                } elseif ($appropriation->expense_type_id && !in_array($appropriation->expense_type_id, $processedTypes)) {
                    $processedTypes[] = $appropriation->expense_type_id;
                    if (!in_array($appropriation->expense_class_id, $processedClasses)) {
                        $processedClasses[] = $appropriation->expense_class_id;
                    }
                    $totalAppropriated += $appropriation->amount;
                } elseif ($appropriation->expense_class_id && !in_array($appropriation->expense_class_id, $processedClasses)) {
                    $processedClasses[] = $appropriation->expense_class_id;
                    $totalAppropriated += $appropriation->amount;
                }
            }

            $totalDisbursed = 0;

            foreach ($budget->tranAppropriations as $appropriation) {
                $totalDisbursed += $appropriation->details()->sum('amount');
            }

            // For supplemental budgets, the available amount should be the total appropriated amount minus disbursed
            // This is because supplemental budgets are created from unused expenses and the appropriations represent available funds
            $unusedAmount = max(0, $totalAppropriated - $totalDisbursed);

            \Log::info('Processing supplemental budget:', [
                'budget_id' => $budget->id,
                'description' => $budget->description,
                'original_amount' => $budget->original_amount,
                'total_appropriated' => $totalAppropriated,
                'total_disbursed' => $totalDisbursed,
                'unused_amount' => $unusedAmount,
                'appropriations_count' => $budget->tranAppropriations->count(),
                'note' => 'Supplemental budgets: available = total_appropriated - total_disbursed'
            ]);

            return [
                'id' => $budget->id,
                'description' => $budget->description,
                'total_amount' => (float)$budget->original_amount,
                'total_appropriated' => (float)$totalAppropriated,
                'total_disbursed' => (float)$totalDisbursed,
                'unused_amount' => (float)$unusedAmount,
                'created_at' => $budget->created_at->format('Y-m-d'),
                'fiscal_year' => $budget->fiscalYear->year ?? '',
                'barangay_name' => $budget->barangay->name ?? '',
                'appropriations' => $budget->tranAppropriations->map(function($appr) {
                    return [
                        'id' => $appr->id,
                        'account_name' => $this->buildAccountName($appr->expenseClass, $appr->expenseType, $appr->expenseItem),
                        'amount' => (float)$appr->amount
                    ];
                })
            ];
        });

        $totalAmount = $supplementalBudgets->sum('total_amount');

        return response()->json([
            'status' => true,
            'data' => $supplementalBudgets,
            'total_amount' => $totalAmount
        ]);
    }

    /**
     * Get fiscal years for dropdown
     */
    public function getFiscalYears(Request $request)
    {
        $request->validate([
            'barangay_id' => 'nullable|exists:barangays,id'
        ]);

        // Filter by barangay
        $query = LibFiscalYear::query();
        if ($request->barangay_id) {
            $query->where('barangay_id', $request->barangay_id);
        } else {
            $user = $request->user('barangay');
            if ($user) {
                $query->where('barangay_id', $user->barangay_id);
            }
        }

        $fiscalYears = $query->orderBy('year', 'desc')->get();

        return response()->json([
            'status' => true,
            'data' => $fiscalYears
        ]);
    }


    /**
     * Helper method to build account names
     */
    private function buildAccountName($expenseClass, $expenseType, $expenseItem)
    {
        $parts = [];
        if ($expenseClass && $expenseClass->name) $parts[] = $expenseClass->name;
        if ($expenseType && $expenseType->name) $parts[] = $expenseType->name;
        if ($expenseItem && $expenseItem->name) $parts[] = $expenseItem->name;
        return implode(' > ', $parts);
    }
}

