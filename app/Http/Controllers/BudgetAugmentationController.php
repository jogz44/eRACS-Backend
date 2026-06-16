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
     * Helper method to build account names without extra separators
     */
    private function buildAccountName($expenseClass, $expenseType, $expenseItem)
    {
        $parts = [];
        if ($expenseClass && $expenseClass->name) $parts[] = $expenseClass->name;
        if ($expenseType && $expenseType->name) $parts[] = $expenseType->name;
        if ($expenseItem && $expenseItem->name) $parts[] = $expenseItem->name;
        return implode(' > ', $parts);
    }

    /**
     * Helper method to build shortened account names for logging
     */
    private function buildShortAccountName($expenseClass, $expenseType, $expenseItem)
    {
        $parts = [];
        if ($expenseClass && $expenseClass->name) {
            // Shorten class name by removing common prefixes and keeping key parts
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
                $detail->fromAppropriation->expenseClass,
                $detail->fromAppropriation->expenseType,
                $detail->fromAppropriation->expenseItem
            ),
            'to_account' => $this->buildAccountName(
                $detail->toAppropriation->expenseClass,
                $detail->toAppropriation->expenseType,
                $detail->toAppropriation->expenseItem
            ),
            'from_budget_source' => $fromBudgetSource,
            'to_budget_source' => $toBudgetSource,
            'amount' => (float)$detail->amount,
            'particulars' => $detail->particulars
        ];
    }



    /**
     * Helper method to perform money transfer between appropriations
     * Handles grouped appropriations by finding all matching appropriations with the same expense hierarchy
     * Can also create new appropriations for unallocated accounts
     */
    private function performTransfer($fromAppropriation, $toAppropriation, $amount, $toExpenseData = null, $userId = null)
    {
        if (!$fromAppropriation) {
            throw new \Exception('Source appropriation not found');
        }

        // Find all appropriations with the same expense hierarchy as the source appropriation
        $sourceAppropriations = TranAppropriation::where('barangay_id', $fromAppropriation->barangay_id)
            ->where('status', 'committed')
            ->where('expense_class_id', $fromAppropriation->expense_class_id)
            ->where('expense_type_id', $fromAppropriation->expense_type_id)
            ->where('expense_item_id', $fromAppropriation->expense_item_id)
            ->orderBy('created_at', 'asc')
            ->get();

        // Calculate total available balance across all source appropriations
        $totalAvailableBalance = $sourceAppropriations->sum('amount');
        
        if ($totalAvailableBalance < $amount) {
            throw new \Exception('Insufficient amount in source appropriation group. Available: ' . $totalAvailableBalance . ', Requested: ' . $amount);
        }

        // Distribute the deduction across source appropriations (FIFO order)
        $remainingAmount = $amount;
        foreach ($sourceAppropriations as $sourceApp) {
            if ($remainingAmount <= 0) break;
            
            $availableInSource = $sourceApp->amount;
            $amountToDeduct = min($remainingAmount, $availableInSource);
            
            $sourceApp->decrement('amount', $amountToDeduct);
            $remainingAmount -= $amountToDeduct;
        }

        // Handle destination appropriation
        if ($toAppropriation) {
            // Existing appropriation - find all appropriations with the same expense hierarchy
            $destinationAppropriations = TranAppropriation::where('barangay_id', $toAppropriation->barangay_id)
                ->where('status', 'committed')
                ->where('expense_class_id', $toAppropriation->expense_class_id)
                ->where('expense_type_id', $toAppropriation->expense_type_id)
                ->where('expense_item_id', $toAppropriation->expense_item_id)
                ->orderBy('created_at', 'asc')
                ->get();

            // Add to the first destination appropriation
            $firstDestination = $destinationAppropriations->first();
            $firstDestination->increment('amount', $amount);

            return [
                'from_appropriation_id' => $fromAppropriation->id,
                'to_appropriation_id' => $toAppropriation->id,
                'amount_transferred' => $amount,
                'source_appropriations_used' => $sourceAppropriations->pluck('id')->toArray(),
                'destination_appropriation_used' => $firstDestination->id
            ];
        } else {
            // Create new appropriation for unallocated account
            if (!$toExpenseData) {
                throw new \Exception('Expense data is required to create new appropriation for unallocated account');
            }

            // Create new appropriation
            $newAppropriation = TranAppropriation::create([
                'barangay_id' => $fromAppropriation->barangay_id,
                'budget_id' => $fromAppropriation->budget_id, // Use same budget as source
                'expense_class_id' => $toExpenseData['expense_class_id'],
                'expense_type_id' => $toExpenseData['expense_type_id'],
                'expense_item_id' => $toExpenseData['expense_item_id'],
                'expense_sub_item_id' => $toExpenseData['expense_sub_item_id'] ?? null,
                'amount' => $amount,
                'status' => 'committed',
                'transaction_date' => now(), // Add transaction_date to avoid NULL constraint violation
                'user_id' => $userId ?? $fromAppropriation->user_id // Add user_id to avoid NULL constraint violation
            ]);

            return [
                'from_appropriation_id' => $fromAppropriation->id,
                'to_appropriation_id' => $newAppropriation->id,
                'amount_transferred' => $amount,
                'source_appropriations_used' => $sourceAppropriations->pluck('id')->toArray(),
                'destination_appropriation_used' => $newAppropriation->id,
                'new_appropriation_created' => true
            ];
        }
    }

    /**
     * Display a listing of budget augmentations
     */
public function index(Request $request)
{
    try {
        $query = BudgetAugmentation::with([
            'budget', 
            'details.fromAppropriation', 
            'details.toAppropriation',
            'barangay'
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
                'details.fromAppropriation', 
                'details.toAppropriation',
                'barangay'
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
            'details.*.from_appropriation_id' => 'required|exists:tran_appropriations,id',
            'details.*.to_appropriation_id' => 'nullable|exists:tran_appropriations,id', // Allow null for unallocated accounts
            'details.*.amount' => 'required|numeric|min:0',
            'details.*.particulars' => 'nullable|string',
            'barangay_id' => 'nullable|exists:barangays,id' // Added for admin
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            // Generate reference number
            $refNumber = 'AUG-' . date('y') . '-' . date('m') . '-' . str_pad(BudgetAugmentation::count() + 1, 3, '0', STR_PAD_LEFT);

            // Calculate total amount
            $totalAmount = collect($request->details)->sum('amount');

            // Find the first appropriation to determine the budget
            $firstDetail = $request->details[0];
            $firstFromAppropriation = TranAppropriation::find($firstDetail['from_appropriation_id']);

            if (!$firstFromAppropriation) {
                throw new \Exception('Source appropriation not found with ID: ' . $firstDetail['from_appropriation_id']);
            }

            // Get the budget from the first appropriation
            $correctBudget = $firstFromAppropriation->budget;

            // Determine barangay_id based on user type
            $barangayId = null;
            if ($request->barangay_id) {
                // Admin user providing barangay_id
                $barangayId = $request->barangay_id;
            } else {
                // Regular user - use their barangay_id
                $barangayId = $request->user()->barangay_id;
            }

            // Create budget augmentation
            $augmentation = BudgetAugmentation::create([
                'barangay_id' => $barangayId, // Use determined barangayId
                'budget_id' => $correctBudget->id,
                'ref_number' => $refNumber,
                'augmentation_date' => $request->augmentation_date,
                'total_amount' => $totalAmount,
                'remarks' => $request->remarks,
                'user_id' => $request->user()->id
            ]);

            $logDetails = [];
            // Create augmentation details and perform transfers
            foreach ($request->details as $detail) {
                // Find source appropriation (FROM)
                $fromAppropriation = TranAppropriation::find($detail['from_appropriation_id']);

                // Find destination appropriation (TO) - can be null for unallocated accounts
                $toAppropriation = $detail['to_appropriation_id'] ? TranAppropriation::find($detail['to_appropriation_id']) : null;

                if (!$fromAppropriation) {
                    throw new \Exception('Source appropriation not found with ID: ' . $detail['from_appropriation_id']);
                }

                // Prepare expense data for creating new appropriation if needed
                $toExpenseData = null;
                if (!$toAppropriation && isset($detail['to_expense_data'])) {
                    $toExpenseData = $detail['to_expense_data'];
                }

                // Perform the actual money transfer
                $transferResult = $this->performTransfer($fromAppropriation, $toAppropriation, $detail['amount'], $toExpenseData, $request->user()->id);

                // Adjust budgets if transfer crosses budgets
                $fromBudgetId = $fromAppropriation->budget_id;
                $toBudgetId = $toAppropriation ? $toAppropriation->budget_id : $fromBudgetId; // New appropriations use same budget as source
                
                if ($fromBudgetId !== $toBudgetId) {
                    // Deduct from source budget's augmentation (money moved out)
                    $fromBudget = \App\Models\Budget::find($fromBudgetId);
                    if ($fromBudget) {
                        $fromBudget->decrement('augmentation', $detail['amount']);
                    }
                    // Add to destination budget's augmentation (money moved in)
                    $toBudget = \App\Models\Budget::find($toBudgetId);
                    if ($toBudget) {
                        $toBudget->increment('augmentation', $detail['amount']);
                    }
                }

                // Create augmentation detail record
                BudgetAugmentationDetail::create([
                    'budget_augmentation_id' => $augmentation->id,
                    'from_appropriation_id' => $fromAppropriation->id,
                    'to_appropriation_id' => $transferResult['to_appropriation_id'], // Use the actual TO appropriation ID (new or existing)
                    'amount' => $detail['amount'],
                    'particulars' => $detail['particulars'] ?? null
                ]);

                // Prepare log detail line with shortened names to avoid truncation
                $fromName = $this->buildShortAccountName($fromAppropriation->expenseClass, $fromAppropriation->expenseType, $fromAppropriation->expenseItem);
                
                // Get the actual TO appropriation for logging (either existing or newly created)
                $actualToAppropriation = TranAppropriation::find($transferResult['to_appropriation_id']);
                $toName = $this->buildShortAccountName($actualToAppropriation->expenseClass, $actualToAppropriation->expenseType, $actualToAppropriation->expenseItem);
                
                // Create shorter log entry without particulars to save space
                $logDetails[] = sprintf('%s → %s ₱%s',
                    $fromName,
                    $toName,
                    number_format((float)$detail['amount'], 2)
                );
            }

            // Note: Budget augmentation per budget was adjusted per-detail when crossing budgets.
            // No aggregate adjustment here to avoid double counting.
            


            // Log creation with shortened message to avoid truncation
            $logMessage = sprintf('#%s total ₱%s', $refNumber, number_format((float)$totalAmount, 2));
            if (!empty($logDetails)) {
                // Limit log details to avoid truncation - show only first few transfers
                $limitedDetails = array_slice($logDetails, 0, 3); // Show max 3 transfers
                $logMessage .= ' | Transfers: ' . implode('; ', $limitedDetails);
                if (count($logDetails) > 3) {
                    $logMessage .= sprintf('; +%d more', count($logDetails) - 3);
                }
            }
            
            AdminAuthController::logUserAction(
                $request->user(),
                'Created Augmentation',
                $logMessage
            );

            return response()->json([
                'status' => true,
                'message' => 'Budget augmentation created successfully',
                'data' => $augmentation->load('details')
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
            'details.fromAppropriation', 
            'details.toAppropriation'
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
        $augmentation = BudgetAugmentation::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'augmentation_date' => 'required|date',
            'remarks' => 'nullable|string',
            'details' => 'required|array|min:1',
            'details.*.from_appropriation_id' => 'required|exists:tran_appropriations,id',
            'details.*.to_appropriation_id' => 'required|exists:tran_appropriations,id',
            'details.*.amount' => 'required|numeric|min:0',
            'details.*.particulars' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        return DB::transaction(function () use ($request, $augmentation) {
            // Calculate new total amount
            $newTotalAmount = collect($request->details)->sum('amount');
            $oldTotalAmount = $augmentation->total_amount;
            $oldRemarks = $augmentation->remarks ?? '';

            // Find the first appropriation to determine the new budget
            $firstDetail = $request->details[0];
            $firstFromAppropriation = TranAppropriation::find($firstDetail['from_appropriation_id']);

            if (!$firstFromAppropriation) {
                throw new \Exception('Source appropriation not found with ID: ' . $firstDetail['from_appropriation_id']);
            }

            // Get the new budget from the first appropriation
            $newBudget = $firstFromAppropriation->budget;

            // Update budget augmentation
            $augmentation->update([
                'budget_id' => $newBudget->id,
                'augmentation_date' => $request->augmentation_date,
                'total_amount' => $newTotalAmount,
                'remarks' => $request->remarks
            ]);

            // First, reverse the old transfers by adding back to FROM appropriations and deducting from TO appropriations
            foreach ($augmentation->details as $oldDetail) {
                $oldFromAppropriation = TranAppropriation::find($oldDetail->from_appropriation_id);
                $oldToAppropriation = TranAppropriation::find($oldDetail->to_appropriation_id);

                if ($oldFromAppropriation && $oldToAppropriation) {
                    // Reverse the old transfer
                    $oldFromAppropriation->increment('amount', $oldDetail->amount);
                    $oldToAppropriation->decrement('amount', $oldDetail->amount);

                    // Reverse budget augmentation effects if transfer crossed budgets
                    if ($oldFromAppropriation->budget_id !== $oldToAppropriation->budget_id) {
                        $fromBudget = \App\Models\Budget::find($oldFromAppropriation->budget_id);
                        $toBudget = \App\Models\Budget::find($oldToAppropriation->budget_id);
                        if ($fromBudget) {
                            // Money returns to source budget: augmentation increases back
                            $fromBudget->increment('augmentation', $oldDetail->amount);
                        }
                        if ($toBudget) {
                            // Money removed from destination budget: augmentation decreases
                            $toBudget->decrement('augmentation', $oldDetail->amount);
                        }
                    }
                }
            }

            // Snapshot old detail summary for logging
            $oldSummary = [];
            foreach ($augmentation->details as $oldDetail) {
                $fromAppr = TranAppropriation::with(['expenseClass','expenseType','expenseItem'])->find($oldDetail->from_appropriation_id);
                $toAppr = TranAppropriation::with(['expenseClass','expenseType','expenseItem'])->find($oldDetail->to_appropriation_id);
                $fromName = $this->buildAccountName($fromAppr?->expenseClass, $fromAppr?->expenseType, $fromAppr?->expenseItem);
                $toName = $this->buildAccountName($toAppr?->expenseClass, $toAppr?->expenseType, $toAppr?->expenseItem);
                $key = $oldDetail->from_appropriation_id . ':' . $oldDetail->to_appropriation_id;
                $oldSummary[$key] = [
                    'from' => $fromName,
                    'to' => $toName,
                    'amount' => (float)$oldDetail->amount,
                ];
            }

            // Delete old details
            $augmentation->details()->delete();

            // Create new details and perform new transfers
            $newSummary = [];
            foreach ($request->details as $detail) {
                // Find source appropriation (FROM)
                $fromAppropriation = TranAppropriation::find($detail['from_appropriation_id']);

                // Find destination appropriation (TO)
                $toAppropriation = TranAppropriation::find($detail['to_appropriation_id']);

                if (!$fromAppropriation) {
                    throw new \Exception('Source appropriation not found with ID: ' . $detail['from_appropriation_id']);
                }

                if (!$toAppropriation) {
                    throw new \Exception('Destination appropriation not found with ID: ' . $detail['to_appropriation_id']);
                }

                // Perform the actual money transfer
                $transferResult = $this->performTransfer($fromAppropriation, $toAppropriation, $detail['amount'], null, $request->user()->id);

                // Adjust budgets if transfer crosses budgets
                if ($fromAppropriation->budget_id !== $toAppropriation->budget_id) {
                    $fromBudget = \App\Models\Budget::find($fromAppropriation->budget_id);
                    $toBudget = \App\Models\Budget::find($toAppropriation->budget_id);
                    if ($fromBudget) {
                        $fromBudget->decrement('augmentation', $detail['amount']);
                    }
                    if ($toBudget) {
                        $toBudget->increment('augmentation', $detail['amount']);
                    }
                }

                // Create augmentation detail record
                BudgetAugmentationDetail::create([
                    'budget_augmentation_id' => $augmentation->id,
                    'from_appropriation_id' => $fromAppropriation->id,
                    'to_appropriation_id' => $toAppropriation->id,
                    'amount' => $detail['amount'],
                    'particulars' => $detail['particulars'] ?? null
                ]);

                // For logging build new summary
                $fromName = $this->buildAccountName($fromAppropriation->expenseClass, $fromAppropriation->expenseType, $fromAppropriation->expenseItem);
                $toName = $this->buildAccountName($toAppropriation->expenseClass, $toAppropriation->expenseType, $toAppropriation->expenseItem);
                $key = $fromAppropriation->id . ':' . $toAppropriation->id;
                $newSummary[$key] = [
                    'from' => $fromName,
                    'to' => $toName,
                    'amount' => (float)$detail['amount'],
                ];
            }

            // Budget augmentation values were adjusted per-detail above to reflect cross-budget transfers.

            // Build unified log for edit
            $topChanges = [];
            $amountChange = null;
            if (($oldRemarks ?? '') !== ($request->remarks ?? '')) { $topChanges[] = sprintf('Remarks "%s" → "%s"', $oldRemarks, $request->remarks ?? ''); }
            if ((float)$oldTotalAmount !== (float)$newTotalAmount) { $amountChange = sprintf('Overall Amount ₱%s → ₱%s', number_format((float)$oldTotalAmount, 2), number_format((float)$newTotalAmount, 2)); }

            $added = [];
            $edited = [];
            $deleted = [];
            $allKeys = array_unique(array_merge(array_keys($oldSummary), array_keys($newSummary)));
            foreach ($allKeys as $key) {
                $old = $oldSummary[$key] ?? null;
                $new = $newSummary[$key] ?? null;
                if ($old && !$new) {
                    $deleted[] = sprintf('%s → %s ₱%s', $old['from'], $old['to'], number_format($old['amount'], 2));
                } elseif (!$old && $new) {
                    $added[] = sprintf('%s → %s ₱%s', $new['from'], $new['to'], number_format($new['amount'], 2));
                } elseif ($old && $new && $old['amount'] !== $new['amount']) {
                    $edited[] = sprintf('%s → %s Amount ₱%s → ₱%s', $new['from'], $new['to'], number_format($old['amount'], 2), number_format($new['amount'], 2));
                }
            }

            $parts = [];
            if (!empty($topChanges)) { $parts[] = implode(', ', $topChanges); }
            if (!empty($added)) { $parts[] = 'Added: ' . implode('; ', $added); }
            if (!empty($edited)) { $parts[] = 'Edited: ' . implode('; ', $edited); }
            if (!empty($deleted)) { $parts[] = 'Deleted: ' . implode('; ', $deleted); }
            if (!empty($amountChange)) { $parts[] = $amountChange; }

            if (!empty($parts)) {
                AdminAuthController::logUserAction(
                    $request->user(),
                    'Edited Augmentation',
                    sprintf('#%s | %s', $augmentation->ref_number, implode(' | ', $parts))
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Budget augmentation updated successfully',
                'data' => $augmentation->load('details')
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