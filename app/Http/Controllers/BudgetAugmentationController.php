<?php

namespace App\Http\Controllers;

use App\Models\BudgetAugmentation;
use App\Models\BudgetAugmentationDetail;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\TranAppropriation; // Added this import
use App\Http\Controllers\AdminAuthController;

class BudgetAugmentationController extends Controller
{
    /**
     * Helper method to build complete six-level account names
     */
    private function buildAccountName(
        $expenseClass = null,
        $expenseType = null,
        $expenseItem = null,
        $expenseSubItem = null,
        $expenseSubType = null,
        $expenseSubSubType = null
    )
    {
        $parts = [];

        if ($expenseClass && $expenseClass->name) {
            $parts[] = $expenseClass->name;
        }

        if ($expenseType && $expenseType->name) {
            $parts[] = $expenseType->name;
        }

        if ($expenseItem && $expenseItem->name) {
            $parts[] = $expenseItem->name;
        }

        if ($expenseSubItem && $expenseSubItem->name) {
            $parts[] = $expenseSubItem->name;
        }

        if ($expenseSubType && $expenseSubType->name) {
            $parts[] = $expenseSubType->name;
        }

        if ($expenseSubSubType && $expenseSubSubType->name) {
            $parts[] = $expenseSubSubType->name;
        }

        return implode(' > ', $parts);
    }

    /**
     * Helper method to build shortened complete account names for logging
     */
    private function buildShortAccountName(
        $expenseClass = null,
        $expenseType = null,
        $expenseItem = null,
        $expenseSubItem = null,
        $expenseSubType = null,
        $expenseSubSubType = null
    )
    {
        $parts = [];

        if ($expenseClass && $expenseClass->name) {
            $className = $expenseClass->name;

            if (strlen($className) > 30) {
                $className = substr($className, 0, 30) . '...';
            }

            $parts[] = $className;
        }

        if ($expenseType && $expenseType->name) {
            $typeName = $expenseType->name;

            if (strlen($typeName) > 20) {
                $typeName = substr($typeName, 0, 20) . '...';
            }

            $parts[] = $typeName;
        }

        if ($expenseItem && $expenseItem->name) {
            $itemName = $expenseItem->name;

            if (strlen($itemName) > 15) {
                $itemName = substr($itemName, 0, 15) . '...';
            }

            $parts[] = $itemName;
        }

        if ($expenseSubItem && $expenseSubItem->name) {
            $subItemName = $expenseSubItem->name;

            if (strlen($subItemName) > 15) {
                $subItemName = substr($subItemName, 0, 15) . '...';
            }

            $parts[] = $subItemName;
        }

        if ($expenseSubType && $expenseSubType->name) {
            $subTypeName = $expenseSubType->name;

            if (strlen($subTypeName) > 15) {
                $subTypeName = substr($subTypeName, 0, 15) . '...';
            }

            $parts[] = $subTypeName;
        }

        if ($expenseSubSubType && $expenseSubSubType->name) {
            $subSubTypeName = $expenseSubSubType->name;

            if (strlen($subSubTypeName) > 15) {
                $subSubTypeName = substr($subSubTypeName, 0, 15) . '...';
            }

            $parts[] = $subSubTypeName;
        }

        return implode(' > ', $parts);
    }

    /**
     * Helper method to map detail to response format
     */
    private function mapDetailToResponse($detail)
    {
        // Get budget source information
        $fromBudgetSource = 'Annual Budget'; // Default
        if ($detail->fromAppropriation && $detail->fromAppropriation->budget) {
            $budgetDescription = $detail->fromAppropriation->budget->description;
            if (str_contains(strtolower($budgetDescription), 'supplemental')) {
                $fromBudgetSource = 'Supplemental Budget';
            } elseif (str_contains(strtolower($budgetDescription), 'annual')) {
                $fromBudgetSource = 'Annual Budget';
            }
        }

        $toBudgetSource = 'Annual Budget'; // Default
        if ($detail->toAppropriation && $detail->toAppropriation->budget) {
            $budgetDescription = $detail->toAppropriation->budget->description;
            if (str_contains(strtolower($budgetDescription), 'supplemental')) {
                $toBudgetSource = 'Supplemental Budget';
            } elseif (str_contains(strtolower($budgetDescription), 'annual')) {
                $toBudgetSource = 'Annual Budget';
            }
        }

        return [
            'id' => $detail->id,
            'from_appropriation_id' => $detail->from_appropriation_id,
            'to_appropriation_id' => $detail->to_appropriation_id,
            'from_account' => $this->buildAccountName(
                $detail->fromAppropriation?->expenseClass,
                $detail->fromAppropriation?->expenseType,
                $detail->fromAppropriation?->expenseItem,
                $detail->fromAppropriation?->expenseSubItem,
                $detail->fromAppropriation?->expenseSubType,
                $detail->fromAppropriation?->expenseSubSubType
            ),
            'to_account' => $this->buildAccountName(
                $detail->toAppropriation?->expenseClass,
                $detail->toAppropriation?->expenseType,
                $detail->toAppropriation?->expenseItem,
                $detail->toAppropriation?->expenseSubItem,
                $detail->toAppropriation?->expenseSubType,
                $detail->toAppropriation?->expenseSubSubType
            ),
            'from_budget_source' => $fromBudgetSource,
            'to_budget_source' => $toBudgetSource,
            'amount' => (float)$detail->amount,
            'particulars' => $detail->particulars
        ];
    }

    /**
     * Helper method to perform money transfer between appropriations.
     *
     * Supports the complete six-level expense hierarchy:
     *
     * Expense Class
     *   -> Expense Type
     *      -> Expense Item
     *         -> Expense Sub Item
     *            -> Expense Sub Type
     *               -> Expense Sub Sub Type
     */
    private function performTransfer(
        $fromAppropriation,
        $toAppropriation,
        $amount,
        $toExpenseData = null,
        $userId = null
    )
    {
        if (!$fromAppropriation) {
            throw new \Exception('Source appropriation not found');
        }

        $amount = (float) $amount;

        if ($amount <= 0) {
            throw new \Exception('Transfer amount must be greater than zero');
        }

        /*
        * =============================================================
        * SOURCE APPROPRIATION GROUP
        * =============================================================
        *
        * Match the COMPLETE six-level hierarchy.
        */
        $sourceAppropriations = TranAppropriation::where(
            'barangay_id',
            $fromAppropriation->barangay_id
        )
            ->where('status', 'committed')
            ->where('expense_class_id', $fromAppropriation->expense_class_id)
            ->where('expense_type_id', $fromAppropriation->expense_type_id)
            ->where('expense_item_id', $fromAppropriation->expense_item_id)
            ->where('expense_sub_item_id', $fromAppropriation->expense_sub_item_id)
            ->where('expense_sub_type_id', $fromAppropriation->expense_sub_type_id)
            ->where(
                'expense_sub_sub_type_id',
                $fromAppropriation->expense_sub_sub_type_id
            )
            ->orderBy('created_at', 'asc')
            ->get();

        $totalAvailableBalance = (float) $sourceAppropriations->sum('amount');

        if ($totalAvailableBalance < $amount) {
            throw new \Exception(
                'Insufficient amount in source appropriation group. ' .
                'Available: ' . number_format($totalAvailableBalance, 2) .
                ', Requested: ' . number_format($amount, 2)
            );
        }

        /*
        * =============================================================
        * DEDUCT FROM SOURCE
        * =============================================================
        */
        $remainingAmount = $amount;

        foreach ($sourceAppropriations as $sourceApp) {
            if ($remainingAmount <= 0) {
                break;
            }

            $availableInSource = (float) $sourceApp->amount;

            if ($availableInSource <= 0) {
                continue;
            }

            $amountToDeduct = min(
                $remainingAmount,
                $availableInSource
            );

            $sourceApp->decrement(
                'amount',
                $amountToDeduct
            );

            $remainingAmount -= $amountToDeduct;
        }

        if ($remainingAmount > 0) {
            throw new \Exception(
                'Unable to complete source appropriation transfer. ' .
                'Remaining amount: ' . number_format($remainingAmount, 2)
            );
        }

        /*
        * =============================================================
        * DESTINATION: EXISTING APPROPRIATION
        * =============================================================
        *
        * IMPORTANT:
        * If the frontend supplied a specific appropriation ID,
        * increment THAT EXACT ROW.
        *
        * Do not search for the oldest matching appropriation.
        */
        if ($toAppropriation) {

            /*
            * Security check:
            * Destination must belong to the same barangay.
            */
            if (
                (int) $toAppropriation->barangay_id !==
                (int) $fromAppropriation->barangay_id
            ) {
                throw new \Exception(
                    'Source and destination appropriations must belong to the same barangay.'
                );
            }

            /*
            * Destination must be committed.
            */
            if ($toAppropriation->status !== 'committed') {
                throw new \Exception(
                    'Destination appropriation is not committed.'
                );
            }

            /*
            * Make sure the selected destination really represents
            * the complete six-level hierarchy supplied by the selected row.
            */
            $hierarchyFields = [
                'expense_class_id',
                'expense_type_id',
                'expense_item_id',
                'expense_sub_item_id',
                'expense_sub_type_id',
                'expense_sub_sub_type_id',
            ];

            foreach ($hierarchyFields as $field) {
                $fromValue = $toAppropriation->{$field};

                /*
                * Nothing else is required here because the destination
                * appropriation itself is the selected account.
                */
            }

            /*
            * ADD TO THE EXACT SELECTED DESTINATION ROW.
            */
            $toAppropriation->increment('amount', $amount);

            return [
                'from_appropriation_id' =>
                    $fromAppropriation->id,

                'to_appropriation_id' =>
                    $toAppropriation->id,

                'amount_transferred' =>
                    $amount,

                'source_appropriations_used' =>
                    $sourceAppropriations
                        ->pluck('id')
                        ->toArray(),

                'destination_appropriation_used' =>
                    $toAppropriation->id,

                'new_appropriation_created' =>
                    false,
            ];
        }

        /*
        * =============================================================
        * DESTINATION: CREATE NEW APPROPRIATION
        * =============================================================
        */
        if (!$toExpenseData) {
            throw new \Exception(
                'Expense data is required to create a new appropriation ' .
                'for unallocated account'
            );
        }

        /*
        * Normalize empty strings to NULL.
        */
        $normalizeId = function ($value) {
            return (
                $value === '' ||
                $value === null
            )
                ? null
                : (int) $value;
        };

        $expenseClassId =
            $normalizeId(
                $toExpenseData['expense_class_id'] ?? null
            );

        $expenseTypeId =
            $normalizeId(
                $toExpenseData['expense_type_id'] ?? null
            );

        $expenseItemId =
            $normalizeId(
                $toExpenseData['expense_item_id'] ?? null
            );

        $expenseSubItemId =
            $normalizeId(
                $toExpenseData['expense_sub_item_id'] ?? null
            );

        $expenseSubTypeId =
            $normalizeId(
                $toExpenseData['expense_sub_type_id'] ?? null
            );

        $expenseSubSubTypeId =
            $normalizeId(
                $toExpenseData['expense_sub_sub_type_id'] ?? null
            );

        /*
        * =============================================================
        * VALIDATE HIERARCHY
        * =============================================================
        */
        if (
            $expenseTypeId !== null &&
            $expenseClassId === null
        ) {
            throw new \Exception(
                'Invalid expense hierarchy: expense_type_id requires expense_class_id.'
            );
        }

        if (
            $expenseItemId !== null &&
            $expenseTypeId === null
        ) {
            throw new \Exception(
                'Invalid expense hierarchy: expense_item_id requires expense_type_id.'
            );
        }

        if (
            $expenseSubItemId !== null &&
            $expenseItemId === null
        ) {
            throw new \Exception(
                'Invalid expense hierarchy: expense_sub_item_id requires expense_item_id.'
            );
        }

        if (
            $expenseSubTypeId !== null &&
            $expenseSubItemId === null
        ) {
            throw new \Exception(
                'Invalid expense hierarchy: expense_sub_type_id requires expense_sub_item_id.'
            );
        }

        if (
            $expenseSubSubTypeId !== null &&
            $expenseSubTypeId === null
        ) {
            throw new \Exception(
                'Invalid expense hierarchy: expense_sub_sub_type_id requires expense_sub_type_id.'
            );
        }

        /*
        * =============================================================
        * FIND EXISTING EXACT DESTINATION
        * =============================================================
        */
        $existingDestination = TranAppropriation::query()
            ->where(
                'barangay_id',
                $fromAppropriation->barangay_id
            )
            ->where('status', 'committed')
            ->where('expense_class_id', $expenseClassId)
            ->where('expense_type_id', $expenseTypeId)
            ->where('expense_item_id', $expenseItemId)
            ->where('expense_sub_item_id', $expenseSubItemId)
            ->where('expense_sub_type_id', $expenseSubTypeId)
            ->where(
                'expense_sub_sub_type_id',
                $expenseSubSubTypeId
            )
            ->orderBy('created_at', 'asc')
            ->first();

        /*
        * If an exact destination already exists, add to it.
        */
        if ($existingDestination) {

            $existingDestination->increment(
                'amount',
                $amount
            );

            return [
                'from_appropriation_id' =>
                    $fromAppropriation->id,

                'to_appropriation_id' =>
                    $existingDestination->id,

                'amount_transferred' =>
                    $amount,

                'source_appropriations_used' =>
                    $sourceAppropriations
                        ->pluck('id')
                        ->toArray(),

                'destination_appropriation_used' =>
                    $existingDestination->id,

                'new_appropriation_created' =>
                    false,
            ];
        }

        /*
        * =============================================================
        * CREATE NEW DESTINATION APPROPRIATION
        * =============================================================
        */
        $newAppropriation = TranAppropriation::create([
            'barangay_id' =>
                $fromAppropriation->barangay_id,

            'budget_id' =>
                $fromAppropriation->budget_id,

            'expense_class_id' =>
                $expenseClassId,

            'expense_type_id' =>
                $expenseTypeId,

            'expense_item_id' =>
                $expenseItemId,

            'expense_sub_item_id' =>
                $expenseSubItemId,

            'expense_sub_type_id' =>
                $expenseSubTypeId,

            'expense_sub_sub_type_id' =>
                $expenseSubSubTypeId,

            'amount' =>
                $amount,

            'status' =>
                'committed',

            'transaction_date' =>
                now(),

            'user_id' =>
                $userId ??
                $fromAppropriation->user_id,
        ]);

        \Log::info(
            'Created augmentation destination appropriation',
            [
                'appropriation_id' =>
                    $newAppropriation->id,

                'barangay_id' =>
                    $newAppropriation->barangay_id,

                'budget_id' =>
                    $newAppropriation->budget_id,

                'expense_class_id' =>
                    $newAppropriation->expense_class_id,

                'expense_type_id' =>
                    $newAppropriation->expense_type_id,

                'expense_item_id' =>
                    $newAppropriation->expense_item_id,

                'expense_sub_item_id' =>
                    $newAppropriation->expense_sub_item_id,

                'expense_sub_type_id' =>
                    $newAppropriation->expense_sub_type_id,

                'expense_sub_sub_type_id' =>
                    $newAppropriation->expense_sub_sub_type_id,

                'amount' =>
                    $newAppropriation->amount,
            ]
        );

        return [
            'from_appropriation_id' =>
                $fromAppropriation->id,

            'to_appropriation_id' =>
                $newAppropriation->id,

            'amount_transferred' =>
                $amount,

            'source_appropriations_used' =>
                $sourceAppropriations
                    ->pluck('id')
                    ->toArray(),

            'destination_appropriation_used' =>
                $newAppropriation->id,

            'new_appropriation_created' =>
                true,
        ];
    }

    /**
     * Display a listing of budget augmentations
     */
    public function index(Request $request)
    {
        try {
            $query = BudgetAugmentation::with([
                'budget',
                'details.fromAppropriation.expenseClass',
                'details.fromAppropriation.expenseType',
                'details.fromAppropriation.expenseItem',
                'details.fromAppropriation.expenseSubItem',
                'details.fromAppropriation.expenseSubType',
                'details.fromAppropriation.expenseSubSubType',

                'details.toAppropriation.expenseClass',
                'details.toAppropriation.expenseType',
                'details.toAppropriation.expenseItem',
                'details.toAppropriation.expenseSubItem',
                'details.toAppropriation.expenseSubType',
                'details.toAppropriation.expenseSubSubType',

                'barangay',
            ])->forBarangay($request->user()->barangay_id);

            // Add year filter
            if ($request->filled('year')) {
                $query->whereHas('budget.fiscalYear', function($q) use ($request) {
                    $q->where('year', $request->year);
                });
            } else {
                $query->whereHas('budget.fiscalYear', function($q) {
                    $q->where('year', now()->year);
                });
            }

            // Apply filters
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('ref_number', 'like', "%{$search}%")
                      ->orWhere('remarks', 'like', "%{$search}%");
                });
            }

            if ($request->filled('date_from')) {
                $query->where('augmentation_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('augmentation_date', '<=', $request->date_to);
            }

            $augmentations = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => true,
                'data' => $augmentations->map(function($augmentation) {
                    return [
                        'id' => $augmentation->id,
                        'ref_number' => $augmentation->ref_number,
                        'augmentation_date' => $augmentation->augmentation_date->format('Y-m-d'),
                        'total_amount' => (float)$augmentation->total_amount,
                        'remarks' => $augmentation->remarks,
                        'barangay_name' => $augmentation->barangay ? $augmentation->barangay->name : 'Unknown',
                        'details' => $augmentation->details->map(function($detail) {
                            return $this->mapDetailToResponse($detail);
                        })
                    ];
                })
            ]);
        } catch (\Exception $e) {
            \Log::error('BudgetAugmentation index error: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching augmentations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin endpoint to fetch augmentations across all barangays
     */
        public function adminIndex(Request $request)
    {
        try {
            $query = BudgetAugmentation::with([
                'budget',

                'details.fromAppropriation.expenseClass',
                'details.fromAppropriation.expenseType',
                'details.fromAppropriation.expenseItem',
                'details.fromAppropriation.expenseSubItem',
                'details.fromAppropriation.expenseSubType',
                'details.fromAppropriation.expenseSubSubType',

                'details.toAppropriation.expenseClass',
                'details.toAppropriation.expenseType',
                'details.toAppropriation.expenseItem',
                'details.toAppropriation.expenseSubItem',
                'details.toAppropriation.expenseSubType',
                'details.toAppropriation.expenseSubSubType',

                'barangay',
            ]);

            // Filter by barangay_id if provided
            if ($request->filled('barangay_id')) {
                $query->where('barangay_id', $request->barangay_id);
            }

            // Add year filter via the budget's fiscal year
            if ($request->filled('year')) {
                $query->whereHas('budget.fiscalYear', function($q) use ($request) {
                    $q->where('year', $request->year);
                });
            } else {
                $query->whereHas('budget.fiscalYear', function($q) {
                    $q->where('year', now()->year);
                });
            }

            // Apply existing filters
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('ref_number', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%");
                });
            }

            if ($request->filled('date_from')) {
                $query->where('augmentation_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('augmentation_date', '<=', $request->date_to);
            }

            $augmentations = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => true,
                'data' => $augmentations->map(function($augmentation) {
                    return [
                        'id' => $augmentation->id,
                        'ref_number' => $augmentation->ref_number,
                        'augmentation_date' => $augmentation->augmentation_date->format('Y-m-d'),
                        'total_amount' => (float)$augmentation->total_amount,
                        'remarks' => $augmentation->remarks,
                        'barangay_name' => $augmentation->barangay ? $augmentation->barangay->name : 'Unknown',
                        'details' => $augmentation->details->map(function($detail) {
                            return $this->mapDetailToResponse($detail);
                        })
                    ];
                })
            ]);
        } catch (\Exception $e) {
            \Log::error('BudgetAugmentation adminIndex error: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching augmentations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created budget augmentation
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'augmentation_date' => 'required|date',
            'remarks' => 'nullable|string',

            'details' => 'required|array|min:1',

            'details.*.from_appropriation_id' =>
                'required|exists:tran_appropriations,id',

            'details.*.to_appropriation_id' =>
                'nullable|exists:tran_appropriations,id',

            'details.*.amount' =>
                'required|numeric|min:0.01',

            'details.*.particulars' =>
                'nullable|string',

            'details.*.to_expense_data' =>
                'nullable|array',

            'details.*.to_expense_data.expense_class_id' =>
                'nullable|integer|exists:lib_expense_classes,id',

            'details.*.to_expense_data.expense_type_id' =>
                'nullable|integer|exists:lib_expense_types,id',

            'details.*.to_expense_data.expense_item_id' =>
                'nullable|integer|exists:lib_expense_items,id',

            'details.*.to_expense_data.expense_sub_item_id' =>
                'nullable|integer|exists:lib_expense_sub_items,id',

            'details.*.to_expense_data.expense_sub_type_id' =>
                'nullable|integer|exists:lib_expense_sub_types,id',

            'details.*.to_expense_data.expense_sub_sub_type_id' =>
                'nullable|integer|exists:lib_expense_sub_sub_types,id',

            // Used by admin users
            'barangay_id' =>
                'nullable|exists:barangays,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($request) {

            /*
            |--------------------------------------------------------------------------
            | DETERMINE BARANGAY
            |--------------------------------------------------------------------------
            */

            $user = $request->user();

            $barangayId = $request->filled('barangay_id')
                ? (int) $request->barangay_id
                : (int) $user->barangay_id;

            if (!$barangayId) {
                throw new \Exception(
                    'Unable to determine barangay for this augmentation.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | GENERATE REFERENCE NUMBER
            |--------------------------------------------------------------------------
            */

            $prefix = 'AUG-' . now()->format('y-m') . '-';

            $lastRef = BudgetAugmentation::withoutGlobalScopes()
                ->where('ref_number', 'like', $prefix . '%')
                ->orderByDesc('ref_number')
                ->value('ref_number');

            $nextNumber = $lastRef
                ? ((int) substr($lastRef, -3)) + 1
                : 1;

            $refNumber = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            /*
            |--------------------------------------------------------------------------
            | CALCULATE TOTAL
            |--------------------------------------------------------------------------
            */

            $totalAmount = collect($request->details)
                ->sum(function ($detail) {
                    return (float) $detail['amount'];
                });

            if ($totalAmount <= 0) {
                throw new \Exception(
                    'Total augmentation amount must be greater than zero.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | GET FIRST SOURCE APPROPRIATION
            |--------------------------------------------------------------------------
            |
            | Used to determine the budget of the augmentation.
            |
            */

            $firstDetail = $request->details[0];

            $firstFromAppropriation = TranAppropriation::query()
                ->where('id', $firstDetail['from_appropriation_id'])
                ->where('barangay_id', $barangayId)
                ->where('status', 'committed')
                ->first();

            if (!$firstFromAppropriation) {
                throw new \Exception(
                    'Source appropriation not found, does not belong to this barangay, ' .
                    'or is not committed. ID: ' .
                    $firstDetail['from_appropriation_id']
                );
            }

            $correctBudget = $firstFromAppropriation->budget;

            if (!$correctBudget) {
                throw new \Exception(
                    'Budget not found for source appropriation ID: ' .
                    $firstFromAppropriation->id
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE AUGMENTATION
            |--------------------------------------------------------------------------
            */

            $augmentation = BudgetAugmentation::create([
                'barangay_id' => $barangayId,
                'budget_id' => $correctBudget->id,
                'ref_number' => $refNumber,
                'augmentation_date' => $request->augmentation_date,
                'total_amount' => $totalAmount,
                'remarks' => $request->remarks,
                'user_id' => $user->id,
            ]);

            $logDetails = [];

            /*
            |--------------------------------------------------------------------------
            | PROCESS TRANSFERS
            |--------------------------------------------------------------------------
            */

            foreach ($request->details as $index => $detail) {

                $amount = (float) $detail['amount'];

                if ($amount <= 0) {
                    throw new \Exception(
                        'Transfer amount must be greater than zero.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | SOURCE
                |--------------------------------------------------------------------------
                */

                $fromAppropriation = TranAppropriation::query()
                    ->where('id', $detail['from_appropriation_id'])
                    ->where('barangay_id', $barangayId)
                    ->where('status', 'committed')
                    ->lockForUpdate()
                    ->first();

                if (!$fromAppropriation) {
                    throw new \Exception(
                        'Source appropriation not found or is not available. ' .
                        'ID: ' . $detail['from_appropriation_id']
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | DESTINATION
                |--------------------------------------------------------------------------
                */

                $toAppropriation = null;

                if (
                    isset($detail['to_appropriation_id']) &&
                    $detail['to_appropriation_id'] !== null &&
                    $detail['to_appropriation_id'] !== ''
                ) {
                    $toAppropriation = TranAppropriation::query()
                        ->where('id', $detail['to_appropriation_id'])
                        ->where('barangay_id', $barangayId)
                        ->where('status', 'committed')
                        ->lockForUpdate()
                        ->first();

                    if (!$toAppropriation) {
                        throw new \Exception(
                            'Destination appropriation not found or is not available. ' .
                            'ID: ' . $detail['to_appropriation_id']
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | PREVENT SAME SOURCE / DESTINATION
                |--------------------------------------------------------------------------
                */

                if (
                    $toAppropriation &&
                    $fromAppropriation->id === $toAppropriation->id
                ) {
                    throw new \Exception(
                        'Source and destination appropriation cannot be the same.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | PREPARE NEW DESTINATION DATA
                |--------------------------------------------------------------------------
                */

                $toExpenseData = null;

                if (!$toAppropriation) {

                    if (
                        !isset($detail['to_expense_data']) ||
                        !is_array($detail['to_expense_data'])
                    ) {
                        throw new \Exception(
                            'Destination expense data is required when no destination appropriation is selected.'
                        );
                    }

                    $toExpenseData = $detail['to_expense_data'];
                }

                /*
                |--------------------------------------------------------------------------
                | PERFORM TRANSFER
                |--------------------------------------------------------------------------
                */

                $transferResult = $this->performTransfer(
                    $fromAppropriation,
                    $toAppropriation,
                    $amount,
                    $toExpenseData,
                    $user->id
                );

                /*
                |--------------------------------------------------------------------------
                | BUDGET ADJUSTMENT
                |--------------------------------------------------------------------------
                */

                $fromBudgetId = $fromAppropriation->budget_id;

                /*
                | If a new destination was created, performTransfer()
                | returns its actual appropriation ID.
                */
                $actualToAppropriation = TranAppropriation::query()
                    ->where('id', $transferResult['to_appropriation_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$actualToAppropriation) {
                    throw new \Exception(
                        'Destination appropriation could not be resolved after transfer.'
                    );
                }

                $toBudgetId = $actualToAppropriation->budget_id;

                /*
                | Only adjust budget augmentation when the transfer
                | actually crosses budgets.
                */
                if ($fromBudgetId !== $toBudgetId) {

                    $fromBudget = Budget::find($fromBudgetId);
                    $toBudget = Budget::find($toBudgetId);

                    if ($fromBudget) {
                        $fromBudget->decrement(
                            'augmentation',
                            $amount
                        );
                    }

                    if ($toBudget) {
                        $toBudget->increment(
                            'augmentation',
                            $amount
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE AUGMENTATION DETAIL
                |--------------------------------------------------------------------------
                */

                BudgetAugmentationDetail::create([
                    'budget_augmentation_id' =>
                        $augmentation->id,

                    'from_appropriation_id' =>
                        $fromAppropriation->id,

                    'to_appropriation_id' =>
                        $transferResult['to_appropriation_id'],

                    'amount' =>
                        $amount,

                    'particulars' =>
                        $detail['particulars'] ?? null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | LOGGING
                |--------------------------------------------------------------------------
                */

                $logDetails[] = sprintf(
                    'From appropriation #%d → To appropriation #%d ₱%s',
                    $fromAppropriation->id,
                    $actualToAppropriation->id,
                    number_format($amount, 2)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | LOG CREATION
            |--------------------------------------------------------------------------
            */

            $logMessage = sprintf(
                '#%s total ₱%s',
                $refNumber,
                number_format($totalAmount, 2)
            );

            if (!empty($logDetails)) {

                $limitedDetails = array_slice($logDetails, 0, 3);

                $logMessage .= ' | Transfers: ' . implode('; ', $limitedDetails);

                if (count($logDetails) > 3) {
                    $logMessage .= sprintf(
                        '; +%d more',
                        count($logDetails) - 3
                    );
                }

                // Keep the activity log within the existing logs.details column size.
                $maxLogDetailsLength = 500;

                if (mb_strlen($logMessage) > $maxLogDetailsLength) {
                    $logMessage = mb_substr(
                        $logMessage,
                        0,
                        $maxLogDetailsLength - 3
                    ) . '...';
                }
            }

            $maxLogDetailsLength = 500;

            if (mb_strlen($logMessage) > $maxLogDetailsLength) {
                $logMessage = mb_substr(
                    $logMessage,
                    0,
                    $maxLogDetailsLength - 3
                ) . '...';
            }

            AdminAuthController::logUserAction(
                $user,
                'Created Augmentation',
                $logMessage
            );

            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'status' => true,
                'message' =>
                    'Budget augmentation created successfully',
                'data' =>
                    $augmentation->load([
                        'details.fromAppropriation',
                        'details.toAppropriation',
                    ]),
            ], 201);
        });
    }

    /**
     * Display the specified budget augmentation
     */
    public function show($id)
    {
        $augmentation = BudgetAugmentation::with([
            'budget',

            'details.fromAppropriation.expenseClass',
            'details.fromAppropriation.expenseType',
            'details.fromAppropriation.expenseItem',
            'details.fromAppropriation.expenseSubItem',
            'details.fromAppropriation.expenseSubType',
            'details.fromAppropriation.expenseSubSubType',

            'details.toAppropriation.expenseClass',
            'details.toAppropriation.expenseType',
            'details.toAppropriation.expenseItem',
            'details.toAppropriation.expenseSubItem',
            'details.toAppropriation.expenseSubType',
            'details.toAppropriation.expenseSubSubType',
        ])->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $augmentation->id,
                'ref_number' => $augmentation->ref_number,
                'augmentation_date' => $augmentation->augmentation_date->format('Y-m-d'),
                'total_amount' => (float)$augmentation->total_amount,
                'remarks' => $augmentation->remarks,
                'budget_id' => $augmentation->budget_id,
                'budget_description' => $augmentation->budget->description ?? '',
                'details' => $augmentation->details->map(function($detail) {
                    return $this->mapDetailToResponse($detail);
                })
            ]
        ]);
    }

    /**
     * Update the specified budget augmentation
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | FIND AUGMENTATION AND VERIFY BARANGAY
        |--------------------------------------------------------------------------
        */
        $augmentation = BudgetAugmentation::query()
            ->where('id', $id)
            ->where('barangay_id', $user->barangay_id)
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */
        $validator = Validator::make($request->all(), [
            'augmentation_date' => 'required|date',
            'remarks' => 'nullable|string',

            'details' => 'required|array|min:1',

            'details.*.from_appropriation_id' =>
                'required|exists:tran_appropriations,id',

            'details.*.to_appropriation_id' =>
                'nullable|exists:tran_appropriations,id',

            'details.*.to_expense_data' =>
                'nullable|array',

            'details.*.to_expense_data.expense_class_id' =>
                'nullable|integer|exists:lib_expense_classes,id',

            'details.*.to_expense_data.expense_type_id' =>
                'nullable|integer|exists:lib_expense_types,id',

            'details.*.to_expense_data.expense_item_id' =>
                'nullable|integer|exists:lib_expense_items,id',

            'details.*.to_expense_data.expense_sub_item_id' =>
                'nullable|integer|exists:lib_expense_sub_items,id',

            'details.*.to_expense_data.expense_sub_type_id' =>
                'nullable|integer|exists:lib_expense_sub_types,id',

            'details.*.to_expense_data.expense_sub_sub_type_id' =>
                'nullable|integer|exists:lib_expense_sub_sub_types,id',

            'details.*.amount' =>
                'required|numeric|min:0.01',

            'details.*.particulars' =>
                'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use (
            $request,
            $augmentation,
            $user
        ) {

            /*
            |--------------------------------------------------------------------------
            | SAVE OLD VALUES FOR LOGGING
            |--------------------------------------------------------------------------
            */

            $oldTotalAmount = (float) $augmentation->total_amount;
            $oldRemarks = $augmentation->remarks ?? '';

            /*
            |--------------------------------------------------------------------------
            | CALCULATE NEW TOTAL
            |--------------------------------------------------------------------------
            */

            $newTotalAmount = collect($request->details)
                ->sum(function ($detail) {
                    return (float) $detail['amount'];
                });

            if ($newTotalAmount <= 0) {
                throw new \Exception(
                    'Total augmentation amount must be greater than zero.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | SNAPSHOT OLD DETAILS BEFORE REVERSING
            |--------------------------------------------------------------------------
            */

            $oldDetails = $augmentation->details()->get();

            $oldSummary = [];

            foreach ($oldDetails as $oldDetail) {

                $fromAppr = TranAppropriation::with([
                    'expenseClass',
                    'expenseType',
                    'expenseItem',
                    'expenseSubItem',
                    'expenseSubType',
                    'expenseSubSubType',
                ])->find($oldDetail->from_appropriation_id);

                $toAppr = TranAppropriation::with([
                    'expenseClass',
                    'expenseType',
                    'expenseItem',
                    'expenseSubItem',
                    'expenseSubType',
                    'expenseSubSubType',
                ])->find($oldDetail->to_appropriation_id);

                $fromName = $this->buildAccountName(
                    $fromAppr?->expenseClass,
                    $fromAppr?->expenseType,
                    $fromAppr?->expenseItem,
                    $fromAppr?->expenseSubItem,
                    $fromAppr?->expenseSubType,
                    $fromAppr?->expenseSubSubType
                );

                $toName = $this->buildAccountName(
                    $toAppr?->expenseClass,
                    $toAppr?->expenseType,
                    $toAppr?->expenseItem,
                    $toAppr?->expenseSubItem,
                    $toAppr?->expenseSubType,
                    $toAppr?->expenseSubSubType
                );

                $key =
                    $oldDetail->from_appropriation_id .
                    ':' .
                    $oldDetail->to_appropriation_id;

                $oldSummary[$key] = [
                    'from' => $fromName,
                    'to' => $toName,
                    'amount' => (float) $oldDetail->amount,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | REVERSE OLD TRANSFERS
            |--------------------------------------------------------------------------
            */

            foreach ($oldDetails as $oldDetail) {

                $oldFromAppropriation = TranAppropriation::query()
                    ->where('id', $oldDetail->from_appropriation_id)
                    ->where('barangay_id', $user->barangay_id)
                    ->where('status', 'committed')
                    ->lockForUpdate()
                    ->first();

                $oldToAppropriation = TranAppropriation::query()
                    ->where('id', $oldDetail->to_appropriation_id)
                    ->where('barangay_id', $user->barangay_id)
                    ->where('status', 'committed')
                    ->lockForUpdate()
                    ->first();

                if (!$oldFromAppropriation) {
                    throw new \Exception(
                        'Original source appropriation not found. ID: ' .
                        $oldDetail->from_appropriation_id
                    );
                }

                if (!$oldToAppropriation) {
                    throw new \Exception(
                        'Original destination appropriation not found. ID: ' .
                        $oldDetail->to_appropriation_id
                    );
                }

                /*
                | Put the old money back into SOURCE.
                */
                $oldFromAppropriation->increment(
                    'amount',
                    $oldDetail->amount
                );

                /*
                | Remove the old transferred money from DESTINATION.
                */
                $oldToAppropriation->decrement(
                    'amount',
                    $oldDetail->amount
                );

                /*
                |--------------------------------------------------------------------------
                | REVERSE CROSS-BUDGET EFFECT
                |--------------------------------------------------------------------------
                */

                if (
                    $oldFromAppropriation->budget_id !==
                    $oldToAppropriation->budget_id
                ) {

                    $fromBudget = Budget::find(
                        $oldFromAppropriation->budget_id
                    );

                    $toBudget = Budget::find(
                        $oldToAppropriation->budget_id
                    );

                    if ($fromBudget) {
                        $fromBudget->increment(
                            'augmentation',
                            $oldDetail->amount
                        );
                    }

                    if ($toBudget) {
                        $toBudget->decrement(
                            'augmentation',
                            $oldDetail->amount
                        );
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | DELETE OLD DETAILS
            |--------------------------------------------------------------------------
            */

            $augmentation->details()->delete();

            /*
            |--------------------------------------------------------------------------
            | PROCESS NEW DETAILS
            |--------------------------------------------------------------------------
            */

            $newSummary = [];

            foreach ($request->details as $detail) {

                $amount = (float) $detail['amount'];

                if ($amount <= 0) {
                    throw new \Exception(
                        'Transfer amount must be greater than zero.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | SOURCE APPROPRIATION
                |--------------------------------------------------------------------------
                */

                $fromAppropriation = TranAppropriation::query()
                    ->where('id', $detail['from_appropriation_id'])
                    ->where('barangay_id', $user->barangay_id)
                    ->where('status', 'committed')
                    ->lockForUpdate()
                    ->first();

                if (!$fromAppropriation) {
                    throw new \Exception(
                        'Source appropriation not found or is not available. ' .
                        'ID: ' .
                        $detail['from_appropriation_id']
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | DESTINATION APPROPRIATION
                |--------------------------------------------------------------------------
                */

                $toAppropriation = null;

                if (
                    isset($detail['to_appropriation_id']) &&
                    $detail['to_appropriation_id'] !== null &&
                    $detail['to_appropriation_id'] !== ''
                ) {

                    /*
                    | IMPORTANT:
                    | Use the EXACT selected appropriation ID.
                    */
                    $toAppropriation = TranAppropriation::query()
                        ->where(
                            'id',
                            $detail['to_appropriation_id']
                        )
                        ->where(
                            'barangay_id',
                            $user->barangay_id
                        )
                        ->where('status', 'committed')
                        ->lockForUpdate()
                        ->first();

                    if (!$toAppropriation) {
                        throw new \Exception(
                            'Destination appropriation not found or is not available. ' .
                            'ID: ' .
                            $detail['to_appropriation_id']
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | SAME SOURCE / DESTINATION CHECK
                |--------------------------------------------------------------------------
                */

                if (
                    $toAppropriation &&
                    $fromAppropriation->id === $toAppropriation->id
                ) {
                    throw new \Exception(
                        'Source and destination appropriation cannot be the same.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | NEW DESTINATION DATA
                |--------------------------------------------------------------------------
                */

                $toExpenseData = null;

                if (!$toAppropriation) {

                    if (
                        !isset($detail['to_expense_data']) ||
                        !is_array($detail['to_expense_data'])
                    ) {
                        throw new \Exception(
                            'Destination appropriation not found and no destination expense data was provided.'
                        );
                    }

                    $toExpenseData =
                        $detail['to_expense_data'];
                }

                /*
                |--------------------------------------------------------------------------
                | PERFORM TRANSFER
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                | performTransfer() is called EXACTLY ONCE.
                |
                */

                $transferResult = $this->performTransfer(
                    $fromAppropriation,
                    $toAppropriation,
                    $amount,
                    $toExpenseData,
                    $user->id
                );

                /*
                |--------------------------------------------------------------------------
                | GET ACTUAL DESTINATION
                |--------------------------------------------------------------------------
                |
                | This is critical.
                |
                | If toAppropriation was null, performTransfer()
                | may have created a new appropriation.
                |
                */

                $actualToAppropriation =
                    TranAppropriation::query()
                        ->where(
                            'id',
                            $transferResult['to_appropriation_id']
                        )
                        ->where(
                            'barangay_id',
                            $user->barangay_id
                        )
                        ->where('status', 'committed')
                        ->lockForUpdate()
                        ->first();

                if (!$actualToAppropriation) {
                    throw new \Exception(
                        'Destination appropriation could not be resolved after transfer. ' .
                        'ID: ' .
                        $transferResult['to_appropriation_id']
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | CROSS-BUDGET ADJUSTMENT
                |--------------------------------------------------------------------------
                */

                if (
                    $fromAppropriation->budget_id !==
                    $actualToAppropriation->budget_id
                ) {

                    $fromBudget = Budget::find(
                        $fromAppropriation->budget_id
                    );

                    $toBudget = Budget::find(
                        $actualToAppropriation->budget_id
                    );

                    if ($fromBudget) {
                        $fromBudget->decrement(
                            'augmentation',
                            $amount
                        );
                    }

                    if ($toBudget) {
                        $toBudget->increment(
                            'augmentation',
                            $amount
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE NEW DETAIL
                |--------------------------------------------------------------------------
                */

                BudgetAugmentationDetail::create([
                    'budget_augmentation_id' =>
                        $augmentation->id,

                    'from_appropriation_id' =>
                        $fromAppropriation->id,

                    'to_appropriation_id' =>
                        $actualToAppropriation->id,

                    'amount' =>
                        $amount,

                    'particulars' =>
                        $detail['particulars'] ?? null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | LOGGING
                |--------------------------------------------------------------------------
                */

                $fromName = $this->buildAccountName(
                    $fromAppropriation->expenseClass,
                    $fromAppropriation->expenseType,
                    $fromAppropriation->expenseItem,
                    $fromAppropriation->expenseSubItem,
                    $fromAppropriation->expenseSubType,
                    $fromAppropriation->expenseSubSubType
                );

                $toName = $this->buildAccountName(
                    $actualToAppropriation->expenseClass,
                    $actualToAppropriation->expenseType,
                    $actualToAppropriation->expenseItem,
                    $actualToAppropriation->expenseSubItem,
                    $actualToAppropriation->expenseSubType,
                    $actualToAppropriation->expenseSubSubType
                );

                $key =
                    $fromAppropriation->id .
                    ':' .
                    $actualToAppropriation->id;

                $newSummary[$key] = [
                    'from' => $fromName,
                    'to' => $toName,
                    'amount' => $amount,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE AUGMENTATION HEADER
            |--------------------------------------------------------------------------
            */

            $firstNewDetail = $request->details[0];

            $firstNewFrom = TranAppropriation::find(
                $firstNewDetail['from_appropriation_id']
            );

            if (!$firstNewFrom || !$firstNewFrom->budget) {
                throw new \Exception(
                    'Unable to determine budget from the new source appropriation.'
                );
            }

            $augmentation->update([
                'budget_id' =>
                    $firstNewFrom->budget_id,

                'augmentation_date' =>
                    $request->augmentation_date,

                'total_amount' =>
                    $newTotalAmount,

                'remarks' =>
                    $request->remarks,
            ]);

            /*
            |--------------------------------------------------------------------------
            | BUILD CHANGE LOG
            |--------------------------------------------------------------------------
            */

            $topChanges = [];
            $amountChange = null;

            if (
                ($oldRemarks ?? '') !==
                ($request->remarks ?? '')
            ) {
                $topChanges[] = sprintf(
                    'Remarks "%s" → "%s"',
                    $oldRemarks,
                    $request->remarks ?? ''
                );
            }

            if (
                (float) $oldTotalAmount !==
                (float) $newTotalAmount
            ) {
                $amountChange = sprintf(
                    'Overall Amount ₱%s → ₱%s',
                    number_format(
                        $oldTotalAmount,
                        2
                    ),
                    number_format(
                        $newTotalAmount,
                        2
                    )
                );
            }

            $added = [];
            $edited = [];
            $deleted = [];

            $allKeys = array_unique(
                array_merge(
                    array_keys($oldSummary),
                    array_keys($newSummary)
                )
            );

            foreach ($allKeys as $key) {

                $old = $oldSummary[$key] ?? null;
                $new = $newSummary[$key] ?? null;

                if ($old && !$new) {

                    $deleted[] = sprintf(
                        '%s → %s ₱%s',
                        $old['from'],
                        $old['to'],
                        number_format(
                            $old['amount'],
                            2
                        )
                    );

                } elseif (!$old && $new) {

                    $added[] = sprintf(
                        '%s → %s ₱%s',
                        $new['from'],
                        $new['to'],
                        number_format(
                            $new['amount'],
                            2
                        )
                    );

                } elseif (
                    $old &&
                    $new &&
                    (float) $old['amount'] !==
                    (float) $new['amount']
                ) {

                    $edited[] = sprintf(
                        '%s → %s Amount ₱%s → ₱%s',
                        $new['from'],
                        $new['to'],
                        number_format(
                            $old['amount'],
                            2
                        ),
                        number_format(
                            $new['amount'],
                            2
                        )
                    );
                }
            }

            $parts = [];

            if (!empty($topChanges)) {
                $parts[] = implode(
                    ', ',
                    $topChanges
                );
            }

            if (!empty($added)) {
                $parts[] =
                    'Added: ' .
                    implode('; ', $added);
            }

            if (!empty($edited)) {
                $parts[] =
                    'Edited: ' .
                    implode('; ', $edited);
            }

            if (!empty($deleted)) {
                $parts[] =
                    'Deleted: ' .
                    implode('; ', $deleted);
            }

            if ($amountChange) {
                $parts[] = $amountChange;
            }

            /*
            |--------------------------------------------------------------------------
            | WRITE AUDIT LOG
            |--------------------------------------------------------------------------
            */

            if (!empty($parts)) {

                AdminAuthController::logUserAction(
                    $user,
                    'Edited Augmentation',
                    sprintf(
                        '#%s | %s',
                        $augmentation->ref_number,
                        implode(' | ', $parts)
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'status' => true,
                'message' =>
                    'Budget augmentation updated successfully',

                'data' =>
                    $augmentation->load([
                        'details.fromAppropriation',
                        'details.toAppropriation',
                    ]),
            ]);
        });
    }

    /**
     * Remove the specified budget augmentation
     */
    public function destroy($id)
    {
        $augmentation = BudgetAugmentation::findOrFail($id);

        return DB::transaction(function () use ($augmentation) {
            // Reverse all transfers by adding back to FROM appropriations and deducting from TO appropriations
            foreach ($augmentation->details as $detail) {
                $fromAppropriation = TranAppropriation::find($detail->from_appropriation_id);
                $toAppropriation = TranAppropriation::find($detail->to_appropriation_id);

                if ($fromAppropriation && $toAppropriation) {
                    // Reverse the transfer
                    $fromAppropriation->increment('amount', $detail->amount);
                    $toAppropriation->decrement('amount', $detail->amount);

                    // Reverse budget augmentation effects if transfer crossed budgets
                    if ($fromAppropriation->budget_id !== $toAppropriation->budget_id) {
                        $fromBudget = \App\Models\Budget::find($fromAppropriation->budget_id);
                        $toBudget = \App\Models\Budget::find($toAppropriation->budget_id);
                        if ($fromBudget) {
                            $fromBudget->increment('augmentation', $detail->amount);
                        }
                        if ($toBudget) {
                            $toBudget->decrement('augmentation', $detail->amount);
                        }
                    }
                }
            }

            // Budget augmentation values were previously adjusted per-detail; no aggregate change here

            // Delete augmentation and details
            $augmentation->delete();

            // Log deletion
            AdminAuthController::logUserAction(
                request()->user(),
                'Deleted Augmentation',
                sprintf('#%s total ₱%s', $augmentation->ref_number, number_format((float)$augmentation->total_amount, 2))
            );

            return response()->json([
                'status' => true,
                'message' => 'Budget augmentation deleted successfully'
            ]);
        });
    }
}
