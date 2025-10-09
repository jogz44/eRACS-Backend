<?php

namespace App\Http\Controllers;

use App\Models\Disbursement;
use App\Models\LibBooklet;
use App\Models\LibCheque;
use App\Models\DisbursementOrDetail;
use App\Models\TranExpenseDetail;
use App\Models\TranAppropriation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AdminAuthController;

class DisbursementController extends Controller
{
    // GET /api/disbursements/recent-liquidated
    public function recentLiquidated(Request $request)
    {
        $user = $request->user();
        $query = Disbursement::where('status', 'Liquidated');
        if ($user && isset($user->barangay_id)) {
            $query->where('barangay_id', $user->barangay_id);
        }
        $disbursements = $query->orderByDesc('liquidated_at')
            ->limit(4)
            ->get(['id', 'dv_number', 'dv_amount', 'date', 'status', 'liquidated_amount', 'liquidated_at']);
        return response()->json([
            'status' => true,
            'data' => $disbursements
        ]);
    }

    private function getAccountNameFromAppropriationId($appropriationId)
    {
        $appr = TranAppropriation::with(['expenseClass', 'expenseType', 'expenseItem'])->find($appropriationId);
        if (!$appr) {
            return 'Unknown Account';
        }
        $parts = [];
        if ($appr->expenseClass) { $parts[] = $appr->expenseClass->name; }
        if ($appr->expenseType) { $parts[] = $appr->expenseType->name; }
        if ($appr->expenseItem) { $parts[] = $appr->expenseItem->name; }
        return implode(' > ', $parts) ?: 'Unknown Account';
    }

    // GET /api/barangay/disbursements
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            \Log::error('Disbursement index: No authenticated user');
            return response()->json([
                'status' => false,
                'message' => 'User not authenticated'
            ], 401);
        }
        $query = Disbursement::with(['bank', 'barangay']);

        // If user is authenticated and has barangay_id, filter by it
        if ($user && isset($user->barangay_id)) {
            $query->where('barangay_id', $user->barangay_id);
        } else {
        }

        // Apply year filter if provided
        if ($request->filled('year')) {
            $query->whereRelation('expenseDetails.appropriation.expenseClass.fiscalYear', 'year', $request->year);
        } else {
            $query->whereRelation('expenseDetails.appropriation.expenseClass.fiscalYear', 'year', now()->year);
        }

        $disbursements = $query->orderByDesc('date')->get();

        $result = $disbursements->map(function($d) {
            return [
                'id' => $d->id,
                'date' => $d->date,
                'dv_number' => $d->dv_number,
                'cheque_number' => $d->cheque_number,
                'bank_id' => $d->bank_id,
                'bank_name' => $d->bank->bank_name,
                'payee' => $d->payee,
                'dv_amount' => $d->dv_amount,
                'status' => $d->status,
                'remarks' => $d->remarks,
                'rejection_remarks' => $d->rejection_remarks,
                'barangay_name' => $d->barangay ? $d->barangay->name : 'Unknown',
                'created_at' => $d->created_at,
                'updated_at' => $d->updated_at,
            ];
        });
        return response()->json([
            'status' => true,
            'data' => $result
        ]);
    }

    // GET /api/admin/disbursements - Admin endpoint to fetch disbursements across all barangays
    public function adminIndex(Request $request)
    {
        $query = Disbursement::with('bank', 'barangay');

        // Filter by barangay_id if provided
        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        }

        $disbursements = $query->orderByDesc('date')->get();
        $result = $disbursements->map(function($d) {
            return [
                'id' => $d->id,
                'date' => $d->date,
                'dv_number' => $d->dv_number,
                'cheque_number' => $d->cheque_number,
                'bank_id' => $d->bank_id,
                'bank_name' => $d->bank->bank_name,
                'payee' => $d->payee,
                'dv_amount' => $d->dv_amount,
                'status' => $d->status,
                'barangay_name' => $d->barangay ? $d->barangay->name : 'Unknown',
                'created_at' => $d->created_at,
                'updated_at' => $d->updated_at,
            ];
        });
        return response()->json([
            'status' => true,
            'data' => $result
        ]);
    }

    public function getParticular(Request $request)
    {
        $particular = TranExpenseDetail::select('particulars')
            ->groupBy('particulars')
            ->orderBy('particulars')
            ->get();

        return response()->json($particular);
    }

    // POST /api/barangay/disbursements
    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',
            'dv_number' => 'required|string|unique:disbursements,dv_number',
            'cheque_number' => 'required|string',
            'bank_id' => 'required|exists:lib_banks,id',
            'payee' => 'required|string',
            'dv_amount' => 'required|numeric|min:0',
            'expenses' => 'array',
            'expenses.*.accountId' => 'required|integer',
            'expenses.*.amount' => 'required|numeric|min:0',
            'expenses.*.particular' => 'nullable|string',
            'expenses.*.expense_class_id' => 'nullable|exists:lib_expense_classes,id',
            'expenses.*.expense_type_id' => 'nullable|exists:lib_expense_types,id',
            'expenses.*.expense_item_id' => 'nullable|exists:lib_expense_items,id',
            'barangay_id' => 'nullable|exists:barangays,id', // Added for admin
        ]);

        try {
            $user = $request->user();
            $adminUser = $request->user('admin');

            // Determine barangay_id based on user type (only honor request for admin)
            $barangayId = $adminUser && $request->filled('barangay_id')
                ? $request->barangay_id
                : $user->barangay_id;

            // Convert date from DD/MM/YYYY to YYYY-MM-DD
            $dateParts = explode('/', $request->date);
            $formattedDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];

            $disbursement = Disbursement::create([
                'barangay_id' => $barangayId,
                'date' => $formattedDate,
                'dv_number' => $request->dv_number,
                'cheque_number' => $request->cheque_number,
                'bank_id' => $request->bank_id,
                'payee' => $request->payee,
                'dv_amount' => $request->dv_amount,
                'status' => 'Unliquidated',
            ]);

            // update the selected lib_cheque_numbers status to 'Used'
            $chequeNumber = $request->cheque_number;
            $cheque = LibCheque::where('cheque_number', $chequeNumber)
                ->where('booklet_id', $request->cheque_booklet) // Assuming cheque_booklet is passed in the request
                ->where('status', 'unused')
                ->firstorFail();

            $cheque->update([
                'status' => 'used',
                'disbursement_id' => $disbursement->id,
            ]);


            // Save expense details to tran_expense_details table
            $logExpenseLines = [];
            if ($request->has('expenses') && is_array($request->expenses)) {
                foreach ($request->expenses as $expense) {
                    // Find the appropriate appropriation based on expense hierarchy
                    $appropriationQuery = TranAppropriation::where('barangay_id', $barangayId)
                        ->where('status', 'committed');

                    if (isset($expense['expense_item_id'])) {
                        $appropriationQuery->where('expense_item_id', $expense['expense_item_id']);
                    } elseif (isset($expense['expense_type_id'])) {
                        $appropriationQuery->whereNull('expense_item_id')
                            ->where('expense_type_id', $expense['expense_type_id']);
                    } elseif (isset($expense['expense_class_id'])) {
                        $appropriationQuery->whereNull('expense_item_id')
                            ->whereNull('expense_type_id')
                            ->where('expense_class_id', $expense['expense_class_id']);
                    }

                    $appropriation = $appropriationQuery->first();

                    if ($appropriation) {
                        // Create expense detail with the disbursement ID
                        TranExpenseDetail::create([
                            'disbursement_id' => $disbursement->id,
                            'appropriation_id' => $appropriation->id,
                            'amount' => $expense['amount'],
                            'particulars' => $expense['particular'] ?? '',
                        ]);

                        // Prepare log line per expense
                        $accountName = $this->getAccountNameFromAppropriationId($appropriation->id);
                        $logExpenseLines[] = sprintf(
                            'Disbursed Expense %s with the amount ₱%s%s',
                            $accountName,
                            number_format((float)$expense['amount'], 2),
                            isset($expense['particular']) && $expense['particular'] !== '' ? ' for "' . $expense['particular'] . '"' : ''
                        );
                    }
                }
            }

            // Log created disbursement
            try {
                $disbursement->load('bank');
                $topLine = sprintf(
                    '#%s for Payee "%s" with the amount ₱%s. Uses %s with the cheque: %s',
                    $disbursement->dv_number,
                    $disbursement->payee,
                    number_format((float)$disbursement->dv_amount, 2),
                    $disbursement->bank ? '(' . $disbursement->bank->bank_name . ')' : '(bank)',
                    $disbursement->cheque_number
                );
                // Header log
                AdminAuthController::logUserAction(
                    $user,
                    'Created Disbursement',
                    $topLine
                );
                // Detail logs per expense (kept concise to avoid length limits)
                foreach ($logExpenseLines as $line) {
                    AdminAuthController::logUserAction(
                        $user,
                        'Disbursed Expense',
                        sprintf('#%s | %s', $disbursement->dv_number, $line)
                    );
                }
            } catch (\Throwable $logEx) {
                \Log::warning('Failed to write disbursement logs: ' . $logEx->getMessage());
            }

            return response()->json([
                'status' => true,
                'message' => 'Disbursement created successfully',
                'data' => $disbursement
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Error creating disbursement: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create disbursement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements
    public function storeReimbursement(Request $request, $id)
    {
        $request->validate([
            'date' => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',
            'dv_number' => 'required|string|unique:disbursements,dv_number',
            'ref_dv_number' => 'required|string|exists:disbursements,dv_number',
            'cheque_number' => 'required|string',
            'bank_id' => 'required|exists:lib_banks,id',
            'payee' => 'required|string',
            'dv_amount' => 'required|numeric|min:0',

            'expenses' => 'array',
            'expenses.*.accountId' => 'required|integer',
            'expenses.*.amount' => 'required|numeric|min:0',
            'expenses.*.particular' => 'nullable|string',
            'expenses.*.expense_class_id' => 'nullable|exists:lib_expense_classes,id',
            'expenses.*.expense_type_id' => 'nullable|exists:lib_expense_types,id',
            'expenses.*.expense_item_id' => 'nullable|exists:lib_expense_items,id',

            'orDetails' => 'required|array',
            'orDetails.*.id' => 'nullable|integer|exists:disbursement_or_details,id',
            'orDetails.*.orNumber' => 'required|string',
            'orDetails.*.orAmount' => 'required|numeric|min:0',
            'orDetails.*.orRefAmount' => 'nullable|numeric|min:0',
            'orDetails.*.orDate' => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',
            'orDetails.*.orPhotoUrl' => 'required|string',
            'orDetails.*.remarks' => 'nullable|string',
            'liquidatedAmount' => 'required|numeric|min:0',

            'barangay_id' => 'nullable|exists:barangays,id', // Added for admin
        ]);

        try{
            $user = $request->user();
            $adminUser = $request->user('admin');

            // Determine barangay_id based on user type (only honor request for admin)
            $barangayId = $adminUser && $request->filled('barangay_id')
                ? $request->barangay_id
                : $user->barangay_id;

            // Find the disbursement
            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            // Check if this is a continuation of partial liquidation
            $isContinuation = $disbursement->status === 'Partial';

            if (!$isContinuation) {
                // Delete existing OR details only if not continuing partial liquidation
                DisbursementOrDetail::where('disbursement_id', $id)->delete();
            }

            // Process OR details - update existing ones or create new ones
            foreach ($request->orDetails as $orDetail) {
                // Convert date from DD/MM/YYYY to YYYY-MM-DD if provided
                $orDate = null;
                if (!empty($orDetail['orDate'])) {
                    $dateParts = explode('/', $orDetail['orDate']);
                    if (count($dateParts) === 3) {
                        $orDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                    }
                }

                if ($isContinuation && isset($orDetail['id']) && $orDetail['id']) {
                    // Update existing OR detail by ID
                    $existingOrDetail = DisbursementOrDetail::where('id', $orDetail['id'])
                        ->where('disbursement_id', $id)
                        ->first();

                    if ($existingOrDetail) {
                        $existingOrDetail->update([
                            'or_date' => $orDate,
                            'or_number' => $orDetail['orNumber'],
                            'or_amount' => $orDetail['orAmount'],
                            'ref_or_amount' => $orDetail['orRefAmount'] ?? null,
                            'remarks' => $orDetail['remarks'] ?? '',
                            'or_photo' => $orDetail['orPhotoUrl'] ?? null,
                        ]);
                    }
                } else {
                    // Create new OR detail
                    DisbursementOrDetail::create([
                        'disbursement_id' => $id,
                        'or_date' => $orDate,
                        'or_number' => $orDetail['orNumber'],
                        'or_amount' => $orDetail['orAmount'],
                        'ref_or_amount' => $orDetail['orRefAmount'] ?? null,
                        'remarks' => $orDetail['remarks'] ?? '',
                        'or_photo' => $orDetail['orPhotoUrl'] ?? null,
                    ]);
                }
            }

            // Update disbursement status based on whether it's partial or full liquidation
            $isPartial = $request->has('isPartial') && ($request->isPartial === true || $request->isPartial === 'true' || $request->isPartial === 1);
            $status = $isPartial ? 'Partial' : 'Liquidated';
            $disbursement->update([
                'status' => $status,
                'liquidated_amount' => $request->liquidatedAmount,
                'liquidated_at' => now(),
            ]);

            // Log liquidation action
            AdminAuthController::logUserAction(
                $user,
                $isPartial ? 'Partial Liquidation' : 'Liquidated Disbursement',
                sprintf(
                    '#%s %s amount ₱%s',
                    $disbursement->dv_number,
                    $isPartial ? 'partialized' : 'liquidated',
                    number_format((float)$request->liquidatedAmount, 2)
                )
            );

            // Convert date from DD/MM/YYYY to YYYY-MM-DD
            $dateParts = explode('/', $request->date);
            $formattedDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];

            $reimbursement = Disbursement::create([
                'barangay_id' => $barangayId,
                'date' => $formattedDate,
                'dv_number' => $request->dv_number,
                'ref_dv_number' => $request->ref_dv_number,
                'cheque_number' => $request->cheque_number,
                'bank_id' => $request->bank_id,
                'payee' => $request->payee,
                'dv_amount' => $request->dv_amount,
                'status' => 'Liquidated',
            ]);

            // update the selected lib_cheque_numbers status to 'Used'
            $chequeNumber = $request->cheque_number;
            $cheque = LibCheque::where('cheque_number', $chequeNumber)
                ->where('status', 'unused')
                ->firstorFail();

            $cheque->update([
                'status' => 'used',
                'disbursement_id' => $reimbursement->id,
            ]);


            // Save expense details to tran_expense_details table
            $logExpenseLines = [];
            if ($request->has('expenses') && is_array($request->expenses)) {
                foreach ($request->expenses as $expense) {
                    // Add debug logging for reimbursement expense lookup
                    \Log::info('Reimbursement: Looking up appropriation for expense', [
                        'expense' => $expense,
                        'barangay_id' => $barangayId
                    ]);

                    // Log all appropriations for this expense hierarchy to understand the data structure
                    $allAppropriations = TranAppropriation::where('barangay_id', $barangayId)
                        ->where('status', 'committed')
                        ->where('expense_class_id', $expense['expense_class_id'])
                        ->where('expense_type_id', $expense['expense_type_id'])
                        ->get(['id', 'expense_class_id', 'expense_type_id', 'expense_item_id', 'expense_sub_item_id', 'amount']);
                    
                    \Log::info('Reimbursement: All appropriations for this expense hierarchy', [
                        'expense_class_id' => $expense['expense_class_id'],
                        'expense_type_id' => $expense['expense_type_id'],
                        'expense_item_id' => $expense['expense_item_id'],
                        'appropriations' => $allAppropriations->toArray()
                    ]);

                    // Find the appropriate appropriation based on expense hierarchy
                    $appropriationQuery = TranAppropriation::where('barangay_id', $barangayId)
                        ->where('status', 'committed');

                    if (isset($expense['expense_item_id'])) {
                        $appropriationQuery->where('expense_item_id', $expense['expense_item_id']);
                    } elseif (isset($expense['expense_type_id'])) {
                        $appropriationQuery->whereNull('expense_item_id')
                            ->where('expense_type_id', $expense['expense_type_id']);
                    } elseif (isset($expense['expense_class_id'])) {
                        $appropriationQuery->whereNull('expense_item_id')
                            ->whereNull('expense_type_id')
                            ->where('expense_class_id', $expense['expense_class_id']);
                    }

                    $appropriation = $appropriationQuery->first();
                    
                    \Log::info('Reimbursement: Appropriation lookup result', [
                        'appropriation_found' => $appropriation ? true : false,
                        'appropriation_id' => $appropriation ? $appropriation->id : null,
                        'appropriation_amount' => $appropriation ? $appropriation->amount : null,
                        'appropriation_details' => $appropriation ? [
                            'expense_class_id' => $appropriation->expense_class_id,
                            'expense_type_id' => $appropriation->expense_type_id,
                            'expense_item_id' => $appropriation->expense_item_id,
                            'expense_sub_item_id' => $appropriation->expense_sub_item_id
                        ] : null
                    ]);
                    
                    // If no appropriation found with expense_item_id, try fallback approach
                    if (!$appropriation && isset($expense['expense_item_id'])) {
                        \Log::info('Reimbursement: No appropriation found with expense_item_id, trying fallback approach');
                        
                        // Try to find appropriation by matching expense hierarchy more flexibly
                        $fallbackQuery = TranAppropriation::where('barangay_id', $barangayId)
                            ->where('status', 'committed');
                            
                        if (isset($expense['expense_class_id'])) {
                            $fallbackQuery->where('expense_class_id', $expense['expense_class_id']);
                        }
                        if (isset($expense['expense_type_id'])) {
                            $fallbackQuery->where('expense_type_id', $expense['expense_type_id']);
                        }
                        // Don't filter by expense_item_id in fallback - try type level first
                        
                        $appropriation = $fallbackQuery->first();
                        
                        \Log::info('Reimbursement: Fallback appropriation lookup result', [
                            'appropriation_found' => $appropriation ? true : false,
                            'appropriation_id' => $appropriation ? $appropriation->id : null,
                            'appropriation_amount' => $appropriation ? $appropriation->amount : null
                        ]);
                    }

                    if ($appropriation) {
                        // BUDGET VALIDATION: Check if there's enough budget for reimbursement
                        $requiredAmount = floatval($expense['amount']);
                        $availableBudget = $this->calculateAvailableBudget($appropriation->id);
                        
                        \Log::info('Reimbursement: Budget validation', [
                            'appropriation_id' => $appropriation->id,
                            'required_amount' => $requiredAmount,
                            'available_budget' => $availableBudget
                        ]);
                        
                        if ($availableBudget < $requiredAmount) {
                            return response()->json([
                                'status' => false,
                                'message' => 'No more budget for this account. Please commit again.',
                                'error' => 'Insufficient budget',
                                'available_budget' => $availableBudget,
                                'required_amount' => $requiredAmount
                            ], 400);
                        }

                        // Create expense detail with the disbursement ID
                        TranExpenseDetail::create([
                            'disbursement_id' => $reimbursement->id,
                            'appropriation_id' => $appropriation->id,
                            'amount' => $expense['amount'],
                            'particulars' => $expense['particular'] ?? '',
                        ]);

                        // Prepare log line per expense
                        $accountName = $this->getAccountNameFromAppropriationId($appropriation->id);
                        $logExpenseLines[] = sprintf(
                            'Disbursed Expense %s with the amount ₱%s%s',
                            $accountName,
                            number_format((float)$expense['amount'], 2),
                            isset($expense['particular']) && $expense['particular'] !== '' ? ' for "' . $expense['particular'] . '"' : ''
                        );
                    } else {
                        \Log::error('Reimbursement: No appropriation found for expense', [
                            'expense' => $expense,
                            'barangay_id' => $barangayId
                        ]);
                        
                        return response()->json([
                            'status' => false,
                            'message' => 'No committed appropriation found for the selected expense account.',
                            'error' => 'Appropriation not found'
                        ], 422);
                    }
                }
            }

            // Log created disbursement
            try {
                $reimbursement->load('bank');
                $topLine = sprintf(
                    '#%s for Payee "%s" with the amount ₱%s. Uses %s with the cheque: %s',
                    $reimbursement->dv_number,
                    $reimbursement->payee,
                    number_format((float)$reimbursement->dv_amount, 2),
                    $reimbursement->bank ? '(' . $reimbursement->bank->bank_name . ')' : '(bank)',
                    $reimbursement->cheque_number
                );
                // Header log
                AdminAuthController::logUserAction(
                    $user,
                    'Created Disbursement',
                    $topLine
                );
                // Detail logs per expense (kept concise to avoid length limits)
                foreach ($logExpenseLines as $line) {
                    AdminAuthController::logUserAction(
                        $user,
                        'Reimbursement Expense',
                        sprintf('#%s | %s', $reimbursement->dv_number, $line)
                    );
                }
            } catch (\Throwable $logEx) {
                \Log::warning('Failed to write reimbursement logs: ' . $logEx->getMessage());
            }

            return response()->json([
                'status' => true,
                'message' => 'Reimbursement created successfully',
                'data' => $reimbursement
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Error saving OR details: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to save OR details',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // GET /api/barangay/disbursements/{id}/or-details
    public function getOrDetails($id)
    {
        $orDetails = DisbursementOrDetail::where('disbursement_id', $id)->get();


        return response()->json(['status' => true, 'data' => $orDetails]);
    }

    // POST /api/barangay/disbursements/{id}/or-details
    public function saveOrDetails(Request $request, $id)
    {
        $request->validate([
            'orDetails' => 'required|array',
            'orDetails.*.id' => 'nullable|integer|exists:disbursement_or_details,id',
            'orDetails.*.orNumber' => 'required|string',
            'orDetails.*.orAmount' => 'required|numeric|min:0',
            'orDetails.*.orDate' => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',
            'orDetails.*.orPhotoUrl' => 'required|string',
            'orDetails.*.remarks' => 'nullable|string',
            'liquidatedAmount' => 'required|numeric|min:0',
            'isPartial' => 'nullable|boolean',
        ]);

        try {
            $user = $request->user();

            // Find the disbursement
            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            // Check if this is a continuation of partial liquidation
            $isContinuation = $disbursement->status === 'Partial';

            if (!$isContinuation) {
                // Delete existing OR details only if not continuing partial liquidation
                DisbursementOrDetail::where('disbursement_id', $id)->delete();
            }

            // Get existing OR details for comparison
            $existingOrDetails = DisbursementOrDetail::where('disbursement_id', $id)->get();

            // Process OR details - update existing ones or create new ones
            foreach ($request->orDetails as $orDetail) {
                // Convert date from DD/MM/YYYY to YYYY-MM-DD if provided
                $orDate = null;
                if (!empty($orDetail['orDate'])) {
                    $dateParts = explode('/', $orDetail['orDate']);
                    if (count($dateParts) === 3) {
                        $orDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                    }
                }

                if ($isContinuation && isset($orDetail['id']) && $orDetail['id']) {
                    // Update existing OR detail by ID
                    $existingOrDetail = DisbursementOrDetail::where('id', $orDetail['id'])
                        ->where('disbursement_id', $id)
                        ->first();

                    if ($existingOrDetail) {
                        $existingOrDetail->update([
                            'or_date' => $orDate,
                            'or_number' => $orDetail['orNumber'],
                            'or_amount' => $orDetail['orAmount'],
                            'remarks' => $orDetail['remarks'] ?? '',
                            'or_photo' => $orDetail['orPhotoUrl'] ?? null,
                        ]);
                    }
                } else {
                    // Create new OR detail
                    DisbursementOrDetail::create([
                        'disbursement_id' => $id,
                        'or_date' => $orDate,
                        'or_number' => $orDetail['orNumber'],
                        'or_amount' => $orDetail['orAmount'],
                        'remarks' => $orDetail['remarks'] ?? '',
                        'or_photo' => $orDetail['orPhotoUrl'] ?? null,
                    ]);
                }
            }



            // Update disbursement status based on whether it's partial or full liquidation
            $isPartial = $request->has('isPartial') && ($request->isPartial === true || $request->isPartial === 'true' || $request->isPartial === 1);
            $status = $isPartial ? 'Partial' : 'Liquidated';
            $disbursement->update([
                'status' => $status,
                'liquidated_amount' => $request->liquidatedAmount,
                'liquidated_at' => now(),
            ]);

            // Log liquidation action
            AdminAuthController::logUserAction(
                $user,
                $isPartial ? 'Partial Liquidation' : 'Liquidated Disbursement',
                sprintf(
                    '#%s %s amount ₱%s',
                    $disbursement->dv_number,
                    $isPartial ? 'partialized' : 'liquidated',
                    number_format((float)$request->liquidatedAmount, 2)
                )
            );

            return response()->json([
                'status' => true,
                'message' => 'OR Details saved successfully',
                'data' => [
                    'disbursement' => $disbursement,
                    'orDetails' => DisbursementOrDetail::where('disbursement_id', $id)->get()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error saving OR details: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to save OR details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements/or-photo/upload
    public function uploadOrPhoto(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // 2MB max
        ]);

        try {
            $user = $request->user();

            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $fileName = time() . '_' . $user->id . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('or-photos', $fileName, 'public');

                return response()->json([
                    'status' => true,
                    'message' => 'Photo uploaded successfully',
                    'path' => $path
                ]);
            }

            return response()->json([
                'status' => false,
                'message' => 'No photo provided'
            ], 400);

        } catch (\Exception $e) {
            \Log::error('Error uploading OR photo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload photo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // DELETE /api/barangay/disbursements/or-photo/delete
    public function deleteOrPhoto(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        try {
            $path = $request->path;

            if (\Storage::disk('public')->exists($path)) {
                \Storage::disk('public')->delete($path);

                return response()->json([
                    'status' => true,
                    'message' => 'Photo deleted successfully'
                ]);
            }

            return response()->json([
                'status' => false,
                'message' => 'Photo not found'
            ], 404);

        } catch (\Exception $e) {
            \Log::error('Error deleting OR photo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete photo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/barangay/disbursements/{id}
    public function show($id)
    {
        try {
            $user = request()->user();

            $query = Disbursement::with(['bank', 'cheque.booklet', 'expenseDetails.appropriation.expenseClass', 'expenseDetails.appropriation.expenseType', 'expenseDetails.appropriation.expenseItem', 'expenseDetails.appropriation.expenseSubItem']);

            // If user is authenticated and has barangay_id, filter by it
            if ($user && isset($user->barangay_id)) {
                $query->where('barangay_id', $user->barangay_id);
            }
            $query->orderByDesc('created_at');


            $disbursement = $query->find($id);
            $reimbursement = null;

            if (!$disbursement) {
                return response()->json(['error' => 'Disbursement not found'], 404);
            }

            // Case 1: this record is a reimbursement (it has a ref_dv_number)
            if ($disbursement->ref_dv_number) {
                $original = Disbursement::with(['bank', 'cheque.booklet', 'expenseDetails.appropriation.expenseClass', 'expenseDetails.appropriation.expenseType', 'expenseDetails.appropriation.expenseItem', 'expenseDetails.appropriation.expenseSubItem'])
                    ->when($user && isset($user->barangay_id), function ($q) use ($user) {
                        $q->where('barangay_id', $user->barangay_id);
                    })
                    ->where('dv_number', $disbursement->ref_dv_number)
                    ->first();

                if ($original) {
                    $reimbursement = $disbursement; // current one is reimbursement
                    $disbursement  = $original;     // swap to the original
                }
            }
            // Case 2: this record is the original (another record references it)
            elseif (
                $linked = Disbursement::with(['bank', 'cheque.booklet', 'expenseDetails.appropriation.expenseClass', 'expenseDetails.appropriation.expenseType', 'expenseDetails.appropriation.expenseItem', 'expenseDetails.appropriation.expenseSubItem'])
                    ->when($user && isset($user->barangay_id), function ($q) use ($user) {
                        $q->where('barangay_id', $user->barangay_id);
                    })
                    ->where('ref_dv_number', $disbursement->dv_number)
                    ->first()
            ) {
                $reimbursement = $linked; // that linked record is the reimbursement
                // $disbursement stays as is
            }

            if (!$disbursement) {
                \Log::warning("Disbursement not found with ID: " . $id);
                return response()->json(['error' => 'Disbursement not found'], 404);
            }

            \Log::info("Found disbursement: ", ['id' => $disbursement->id, 'dv_number' => $disbursement->dv_number]);


            $data = [
                'id' => $disbursement->id,
                'date' => $disbursement->date,
                'dv_number' => $disbursement->dv_number,
                'ref_dv_number' => $disbursement->ref_dv_number ?? null,
                'cheque_number' => $disbursement->cheque_number,
                'bank_id' => $disbursement->bank_id,
                'bank_name' => $disbursement->bank ? $disbursement->bank->bank_name : null,
                'booklet_id' => $disbursement->cheque ? $disbursement->cheque->booklet_id : null,
                'payee' => $disbursement->payee,
                'dv_amount' => $disbursement->dv_amount,
                'status' => $disbursement->status,
                'expenses' => $disbursement->expenseDetails->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'accountId' => $detail->appropriation_id,
                        'account_name' => '' . $detail->appropriation->expenseClass->name
                            . ($detail->appropriation->expenseType ? ' > ' . $detail->appropriation->expenseType->name : '')
                            . ($detail->appropriation->expenseItem ? ' > ' . $detail->appropriation->expenseItem->name : '')
                            . ($detail->appropriation->expenseSubItem ? ' > ' . $detail->appropriation->expenseSubItem->name : ''),
                        'amount' => $detail->amount,
                        'particular' => $detail->particulars,
                        'expense_class_id' => $detail->appropriation->expense_class_id ?? null,
                        'expense_type_id' => $detail->appropriation->expense_type_id ?? null,
                        'expense_item_id' => $detail->appropriation->expense_item_id ?? null,
                        'expense_sub_item_id' => $detail->appropriation->expense_sub_item_id ?? null,
                    ];
                }),
                'created_at' => $disbursement->created_at,
                'updated_at' => $disbursement->updated_at,
            ];

            // If this disbursement has a reimbursement (linked either way)
            if ($reimbursement) {
                // Pick whichever exists

                $data['reimbursement'] = [
                    'id' => $reimbursement->id,
                    'date' => $reimbursement->date,
                    'dv_number' => $reimbursement->dv_number,
                    'ref_dv_number' => $reimbursement->ref_dv_number ?? null,
                    'cheque_number' => $reimbursement->cheque_number,
                    'bank_id' => $reimbursement->bank_id,
                    'bank_name' => $reimbursement->bank ? $reimbursement->bank->bank_name : null,
                    'booklet_id' => $reimbursement->cheque ? $reimbursement->cheque->booklet_id : null,
                    'payee' => $reimbursement->payee,
                    'dv_amount' => $reimbursement->dv_amount,
                    'status' => $reimbursement->status,
                    'expenses' => $reimbursement->expenseDetails->map(function ($detail) {
                        return [
                            'id' => $detail->id,
                            'accountId' => $detail->appropriation_id,
                            'account_name' => '' . $detail->appropriation->expenseClass->name
                                . ($detail->appropriation->expenseType ? ' > ' . $detail->appropriation->expenseType->name : '')
                                . ($detail->appropriation->expenseItem ? ' > ' . $detail->appropriation->expenseItem->name : '')
                                . ($detail->appropriation->expenseSubItem ? ' > ' . $detail->appropriation->expenseSubItem->name : ''),
                            'amount' => $detail->amount,
                            'particular' => $detail->particulars,
                            'expense_class_id' => $detail->appropriation->expense_class_id ?? null,
                            'expense_type_id' => $detail->appropriation->expense_type_id ?? null,
                            'expense_item_id' => $detail->appropriation->expense_item_id ?? null,
                            'expense_sub_item_id' => $detail->appropriation->expense_sub_item_id ?? null,
                        ];
                    }),
                    'created_at' => $reimbursement->created_at,
                    'updated_at' => $reimbursement->updated_at,
                ];
            }

            return response()->json([
                'status' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            \Log::error("Error fetching disbursement: " . $e->getMessage());
            return response()->json(['error' => 'Internal server error'. $e->getMessage()], 500);
        }
    }

    // PUT /api/barangay/disbursements/{id}
    public function update(Request $request, $id)
    {
        $request->validate([
            'cancel'        => 'required|boolean',
            'bank_id'       => 'required_if:cancel,true|exists:lib_banks,id',
            'cheque_number' => 'required_if:cancel,true|string',
            'payee'         => 'required_if:cancel,true|string',


            'date' => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',
            'dv_number' => 'required|string',
            'dv_amount' => 'required|numeric|min:0',
            'expenses' => 'array',
            'expenses.*.id' => 'nullable|exists:tran_expense_details,id',
            'expenses.*.accountId' => 'required|integer',
            'expenses.*.amount' => 'required|numeric|min:0',
            'expenses.*.particular' => 'nullable|string',
            'expenses.*.expense_class_id' => 'nullable|exists:lib_expense_classes,id',
            'expenses.*.expense_type_id' => 'nullable|exists:lib_expense_types,id',
            'expenses.*.expense_item_id' => 'nullable|exists:lib_expense_items,id',
        ]);

        try {
            $user = $request->user();

            // Find the disbursement
            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            // Convert date from DD/MM/YYYY to YYYY-MM-DD
            $dateParts = explode('/', $request->date);
            $formattedDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];

            // Capture previous values for logging
            $prev = [
                'date' => $disbursement->date,
                'dv_number' => $disbursement->dv_number,
                'cheque_number' => $disbursement->cheque_number,
                'bank_id' => $disbursement->bank_id,
                'payee' => $disbursement->payee,
                'dv_amount' => $disbursement->dv_amount,
            ];

            // Update the disbursement
            $disbursement->update([
                'date' => $formattedDate,
                'dv_number' => $request->dv_number,
                'dv_amount' => $request->dv_amount,
            ]);
            if($request->cancel){
                $disbursement->update([
                    'cheque_number' => $request->cheque_number,
                    'bank_id' => $request->bank_id,
                    'payee' => $request->payee,
                ]);
                $cheque=LibCheque::where('disbursement_id', $id)
                    ->first();
                    
                if ($cheque) {
                    $cheque->status = 'cancelled';
                    $cheque->save();
                }

                $newbooklets = LibBooklet::where('bank_id', $request->bank_id)->get();
                $newcheque = LibCheque::where('cheque_number', $request->cheque_number)
                    ->whereIn('booklet_id', $newbooklets->pluck('id'))
                    ->first();

                if ($newcheque) {
                    $newcheque->status = 'used';
                    $newcheque->disbursement_id = $id;
                    $newcheque->save();
                }
            }

            // Update bank and booklet statuses after voiding cheque
            $bankLibraryController = new \App\Http\Controllers\Library\BankLibraryController();
            $bankLibraryController->updateBanksStatus();

            // Update expense details - handle existing and new ones
            if ($request->has('expenses') && is_array($request->expenses)) {
                // Get existing expense detail IDs for this disbursement
                $existingExpenseDetails = TranExpenseDetail::where('disbursement_id', $disbursement->id)
                    ->get();
                $existingExpenseDetailIds = $existingExpenseDetails->pluck('id')->toArray();
                $existingExpenseDetailMap = $existingExpenseDetails->keyBy('id');
                $expenseAddedLogs = [];
                $expenseEditedLogs = [];
                $expenseDeletedLogs = [];

                // Process each expense
                foreach ($request->expenses as $expense) {
                    // Find the appropriate appropriation based on expense hierarchy
                    $appropriationQuery = TranAppropriation::where('barangay_id', $user->barangay_id)
                        ->where('status', 'committed');

                    if (isset($expense['expense_item_id'])) {
                        $appropriationQuery->where('expense_item_id', $expense['expense_item_id']);
                    } elseif (isset($expense['expense_type_id'])) {
                        $appropriationQuery->whereNull('expense_item_id')
                            ->where('expense_type_id', $expense['expense_type_id']);
                    } elseif (isset($expense['expense_class_id'])) {
                        $appropriationQuery->whereNull('expense_item_id')
                            ->whereNull('expense_type_id')
                            ->where('expense_class_id', $expense['expense_class_id']);
                    }

                    $appropriation = $appropriationQuery->first();

                    if ($appropriation) {
                        if (isset($expense['id']) && in_array($expense['id'], $existingExpenseDetailIds)) {
                            // Update existing expense detail
                            $existing = $existingExpenseDetailMap[$expense['id']];
                            $previousAmount = (float) $existing->amount;
                            $previousParticulars = $existing->particulars ?? '';
                            $previousAppropriationId = $existing->appropriation_id;

                            TranExpenseDetail::where('id', $expense['id'])->update([
                                'appropriation_id' => $appropriation->id,
                                'amount' => $expense['amount'],
                                'particulars' => $expense['particular'] ?? '',
                            ]);

                            // Log edit specifics
                            $newAmount = (float) $expense['amount'];
                            $newParticulars = $expense['particular'] ?? '';
                            $changedFields = [];

                            $newName = $this->getAccountNameFromAppropriationId($appropriation->id);
                            $accountDisplay = $previousAppropriationId !== $appropriation->id
                                ? sprintf('%s', $newName)
                                : $newName;
                            // Detect amount change
                            if ($previousAmount !== $newAmount) {
                                $changedFields[] = sprintf('Amount ₱%s → ₱%s', number_format($previousAmount, 2), number_format($newAmount, 2));
                            }
                            // Detect particulars change
                            if ($previousParticulars !== $newParticulars) {
                                $changedFields[] = sprintf('Particulars "%s" → "%s"', $previousParticulars, $newParticulars);
                            }

                            if (!empty($changedFields)) {
                                $expenseEditedLogs[] = sprintf('%s%s', $accountDisplay, empty($changedFields) ? '' : ' | ' . implode(', ', $changedFields));
                            }
                        } else {
                            // Create new expense detail
                            $created = TranExpenseDetail::create([
                                'disbursement_id' => $disbursement->id,
                                'appropriation_id' => $appropriation->id,
                                'amount' => $expense['amount'],
                                'particulars' => $expense['particular'] ?? '',
                            ]);

                            // Log added expense account
                            $accountName = $this->getAccountNameFromAppropriationId($appropriation->id);
                            $expenseAddedLogs[] = sprintf('%s amount ₱%s%s',
                                $accountName,
                                number_format((float)$expense['amount'], 2),
                                isset($expense['particular']) && $expense['particular'] !== '' ? ' | Particulars: "' . $expense['particular'] . '"' : ''
                            );
                        }
                    }
                }

                // Delete any remaining expense details that are no longer in the request
                $requestedIds = collect($request->expenses)
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                $toDeleteIds = array_diff($existingExpenseDetailIds, $requestedIds);
                foreach ($toDeleteIds as $delId) {
                    $detail = $existingExpenseDetailMap[$delId] ?? null;
                    if ($detail) {
                        $accountName = $this->getAccountNameFromAppropriationId($detail->appropriation_id);
                        $expenseDeletedLogs[] = sprintf('%s amount ₱%s%s',
                            $accountName,
                            number_format((float)$detail->amount, 2),
                            $detail->particulars ? ' | Particulars: "' . $detail->particulars . '"' : ''
                        );
                    }
                    TranExpenseDetail::where('id', $delId)->delete();
                }

                // Build unified log message for expense changes and top-level updates
                $topLevelChanges = [];
                $amountChange = null;
                if ($prev['dv_number'] !== $disbursement->dv_number) { $topLevelChanges[] = sprintf('DV# %s → %s', $prev['dv_number'], $disbursement->dv_number); }
                if ($prev['payee'] !== $disbursement->payee) { $topLevelChanges[] = sprintf('Payee %s → %s', $prev['payee'], $disbursement->payee); }
                if ($prev['date'] !== $disbursement->date) { $topLevelChanges[] = sprintf('Date %s → %s', $prev['date'], $disbursement->date); }
                if ((float)$prev['dv_amount'] !== (float)$disbursement->dv_amount) { $amountChange = sprintf('Overall Amount ₱%s → ₱%s', number_format((float)$prev['dv_amount'], 2), number_format((float)$disbursement->dv_amount, 2)); }

                $parts = [];
                if (!empty($topLevelChanges)) {
                    $parts[] = implode(', ', $topLevelChanges);
                }
                if (!empty($expenseAddedLogs)) {
                    $parts[] = 'Added: ' . implode('; ', $expenseAddedLogs);
                }
                if (!empty($expenseEditedLogs)) {
                    $parts[] = 'Edited: ' . implode('; ', $expenseEditedLogs);
                }
                if (!empty($expenseDeletedLogs)) {
                    $parts[] = 'Deleted: ' . implode('; ', $expenseDeletedLogs);
                }
                if (!empty($amountChange)) {
                    $parts[] = $amountChange;
                }

                if (!empty($parts)) {
                    AdminAuthController::logUserAction(
                        $user,
                        'Edited Disbursement',
                        sprintf(
                            '#%s | %s',
                            $disbursement->dv_number,
                            implode(' | ', $parts)
                        )
                    );
                }
            }


            return response()->json([
                'status' => true,
                'message' => 'Disbursement updated successfully',
                'data' => $disbursement
            ]);

        } catch (\Exception $e) {
            \Log::error('Error updating disbursement: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update disbursement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // PATCH /api/barangay/disbursements/{id}/liquidate
    public function liquidate(Request $request, $id)
    {
        $request->validate([
            'liquidated_amount' => 'required|numeric|min:0',
        ]);
        $user = $request->user();
        $disbursement = Disbursement::findOrFail($id);
        $previous = (float) ($disbursement->liquidated_amount ?? 0);
        $disbursement->status = 'Liquidated';
        $disbursement->liquidated_amount = $request->liquidated_amount;
        $disbursement->liquidated_at = now();
        $disbursement->save();

        // Log liquidation via direct endpoint
        AdminAuthController::logUserAction(
            $user,
            'Liquidated Disbursement',
            sprintf(
                '#%s liquidated amount ₱%s (prev ₱%s)',
                $disbursement->dv_number,
                number_format((float)$disbursement->liquidated_amount, 2),
                number_format($previous, 2)
            )
        );

        return response()->json(['status' => true, 'data' => $disbursement]);
    }

    // DELETE /api/barangay/disbursements/{id}/or-details/{orDetailId}
    public function deleteOrDetail(Request $request, $id, $orDetailId)
    {
        try {
            $user = $request->user();

            // Find the disbursement and ensure it belongs to the user's barangay
            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            // Check if disbursement can be modified (only if status is Unliquidated or Partial)
            if ($disbursement->status !== 'Unliquidated' && $disbursement->status !== 'Partial') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only unliquidated and partial disbursements can be modified'
                ], 400);
            }

            // Find and delete the OR detail
            $orDetail = DisbursementOrDetail::where('id', $orDetailId)
                ->where('disbursement_id', $id)
                ->firstOrFail();

            $orDetail->delete();

            return response()->json([
                'status' => true,
                'message' => 'OR Detail deleted successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error deleting OR detail: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete OR detail',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // DELETE /api/barangay/disbursements/{id}
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Find the disbursement and ensure it belongs to the user's barangay
            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            // Check if disbursement can be deleted (only if status is Unliquidated or Partial)
            if ($disbursement->status !== 'Unliquidated' && $disbursement->status !== 'Partial') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only Unliquidated and partial disbursements can be deleted'
                ], 400);
            }

            // Delete expense details first (they will be automatically deleted due to cascade, but being explicit)
            TranExpenseDetail::where('disbursement_id', $disbursement->id)->delete();

            // Delete the disbursement
            $disbursement->delete();

            // Log deletion
            AdminAuthController::logUserAction(
                $user,
                'Deleted Disbursement',
                sprintf(
                    'Deleted #%s for %s amount ₱%s',
                    $disbursement->dv_number,
                    $disbursement->payee,
                    number_format((float)$disbursement->dv_amount, 2)
                )
            );

            return response()->json([
                'status' => true,
                'message' => 'Disbursement deleted successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error deleting disbursement: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete disbursement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements/{id}/void-request
    public function requestVoid(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Validate remarks
            $request->validate([
                'remarks' => 'required|string|max:500'
            ]);

            // Allow any authenticated barangay user to request void

            // Find the disbursement and ensure it belongs to the user's barangay
            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            // Can only request void for Unliquidated or Partial
            if (!in_array($disbursement->status, ['Unliquidated', 'Partial'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Only Unliquidated or partial disbursements can be void requested'
                ], 400);
            }

            // Check aging restriction (≤ 1 day can be voided)
            $disbursementDate = new \DateTime($disbursement->date);
            $today = new \DateTime();
            $aging = $today->diff($disbursementDate)->days;

            // if ($aging < 0) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'Only disbursements aged 0 day or more can be voided'
            //     ], 400);
            // }

            // Update status to Void Requested and save remarks
            $disbursement->status = 'Void Requested';
            $disbursement->remarks = $request->remarks;
            $disbursement->save();

            // Log action
            AdminAuthController::logUserAction(
                $user,
                'Requested Void',
                sprintf('#%s requested void', $disbursement->dv_number)
            );

            return response()->json([
                'status' => true,
                'message' => 'Void request submitted',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error requesting void: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to submit void request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements/{id}/void-approve
    public function approveVoid(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Only Captain/Chairperson can approve
            $positionName = $user->position ? $user->position->name : '';
            $position = strtolower($positionName);
            \Log::info('User position for void approval: ' . $positionName . ' (lowercase: ' . $position . ')');

            // Check if user has approval role (Captain or Chairperson)
            $canApprove = strpos($position, 'captain') !== false ||
                         strpos($position, 'chairperson') !== false ||
                         strpos($position, 'barangay captain') !== false ||
                         strpos($position, 'sk chairperson') !== false;

            if (!$canApprove) {
                \Log::warning('User not authorized for void approval. Position: ' . $positionName);
                return response()->json([
                    'status' => false,
                    'message' => 'Only Captain/Chairperson can approve void requests'
                ], 403);
            }

            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            if ($disbursement->status !== 'Void Requested') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only disbursements with status "Void Requested" can be approved'
                ], 400);
            }

            $disbursement->status = 'Voided';
            $disbursement->save();

            // change status of cheque number in LibCheque to 'voided'
            $booklets = LibBooklet::where('bank_id', $disbursement->bank_id)->get();

            $cheque = LibCheque::where('cheque_number', $disbursement->cheque_number)
                ->whereIn('booklet_id', $booklets->pluck('id'))
                ->first();

            if ($cheque) {
                $cheque->status = 'void';
                $cheque->save();

                // Update bank and booklet statuses after voiding cheque
                $bankLibraryController = new \App\Http\Controllers\Library\BankLibraryController();
                $bankLibraryController->updateBanksStatus();
            }


            AdminAuthController::logUserAction(
                $user,
                'Approved Void',
                sprintf('#%s approved void', $disbursement->dv_number)
            );

            return response()->json([
                'status' => true,
                'message' => 'Void request approved',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error approving void: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to approve void request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements/{id}/void-reject
    public function rejectVoid(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Validate rejection remarks
            $request->validate([
                'remarks' => 'required|string|max:500'
            ]);

            // Only Captain/Chairperson can reject
            $positionName = $user->position ? $user->position->name : '';
            $position = strtolower($positionName);
            \Log::info('User position for void rejection: ' . $positionName . ' (lowercase: ' . $position . ')');

            // Check if user has approval role (Captain or Chairperson)
            $canReject = strpos($position, 'captain') !== false ||
                        strpos($position, 'chairperson') !== false ||
                        strpos($position, 'barangay captain') !== false ||
                        strpos($position, 'sk chairperson') !== false;

            if (!$canReject) {
                \Log::warning('User not authorized for void rejection. Position: ' . $positionName);
                return response()->json([
                    'status' => false,
                    'message' => 'Only Captain/Chairperson can reject void requests'
                ], 403);
            }

            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            if ($disbursement->status !== 'Void Requested') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only disbursements with status "Void Requested" can be rejected'
                ], 400);
            }

            // On reject, return to Unliquidated and save rejection remarks
            $disbursement->status = 'Unliquidated';
            $disbursement->rejection_remarks = $request->remarks;
            $disbursement->save();

            AdminAuthController::logUserAction(
                $user,
                'Rejected Void',
                sprintf('#%s rejected void', $disbursement->dv_number)
            );

            return response()->json([
                'status' => true,
                'message' => 'Void request rejected',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error rejecting void: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to reject void request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements/{id}/void-direct
    public function voidDirect(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Validate remarks
            $request->validate([
                'remarks' => 'required|string|max:500'
            ]);

            // Only Captain/Chairperson can void directly
            $positionName = $user->position ? $user->position->name : '';
            $position = strtolower($positionName);
            \Log::info('User position for direct void: ' . $positionName . ' (lowercase: ' . $position . ')');

            // Check if user has approval role (Captain or Chairperson)
            $canVoidDirectly = strpos($position, 'captain') !== false ||
                              strpos($position, 'chairperson') !== false ||
                              strpos($position, 'barangay captain') !== false ||
                              strpos($position, 'sk chairperson') !== false;

            if (!$canVoidDirectly) {
                \Log::warning('User not authorized for direct void. Position: ' . $positionName);
                return response()->json([
                    'status' => false,
                    'message' => 'Only Captain/Chairperson can void disbursements directly'
                ], 403);
            }

            // Find the disbursement and ensure it belongs to the user's barangay
            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            // Can only void Unliquidated or Partial disbursements
            if (!in_array($disbursement->status, ['Unliquidated', 'Partial'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Only Unliquidated or partial disbursements can be voided'
                ], 400);
            }

            // Check aging restriction (≤ 1 day can be voided)
            $disbursementDate = new \DateTime($disbursement->date);
            $today = new \DateTime();
            $aging = $today->diff($disbursementDate)->days;

            // if ($aging > 1) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'Only disbursements aged 1 day or less can be voided'
            //     ], 400);
            // }

            // Update status to Voided and save remarks
            $disbursement->status = 'Voided';
            $disbursement->remarks = $request->remarks;
            $disbursement->save();

            // Change status of cheque number in LibCheque to 'voided'
            $booklets = LibBooklet::where('bank_id', $disbursement->bank_id)->get();

            $cheque = LibCheque::where('cheque_number', $disbursement->cheque_number)
                ->whereIn('booklet_id', $booklets->pluck('id'))
                ->first();

            if ($cheque) {
                $cheque->status = 'void';
                $cheque->save();

                // Update bank and booklet statuses after voiding cheque
                $bankLibraryController = new \App\Http\Controllers\Library\BankLibraryController();
                $bankLibraryController->updateBanksStatus();
            }

            // Log action
            AdminAuthController::logUserAction(
                $user,
                'Voided Disbursement Directly',
                sprintf('#%s voided directly by %s', $disbursement->dv_number, $positionName)
            );

            return response()->json([
                'status' => true,
                'message' => 'Disbursement voided successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error voiding disbursement directly: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to void disbursement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements/{id}/edit-request
    public function requestEdit(Request $request, $id)
    {
        try {
            $user = $request->user();

            $request->validate([
                'remarks' => 'required|string|max:500'
            ]);

            // Any authenticated barangay user can request edit
            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            // Only allow request when Unliquidated or Partial (same as void)
            if (!in_array($disbursement->status, ['Unliquidated', 'Partial'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Only Unliquidated or Partial disbursements can be edit requested'
                ], 400);
            }

            $disbursement->status = 'Edit Requested';
            $disbursement->remarks = $request->remarks;
            $disbursement->save();

            AdminAuthController::logUserAction(
                $user,
                'Requested Edit',
                sprintf('#%s requested edit', $disbursement->dv_number)
            );

            return response()->json([
                'status' => true,
                'message' => 'Edit request submitted',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error requesting edit: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to submit edit request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements/{id}/edit-approve
    public function approveEdit(Request $request, $id)
    {
        try {
            $user = $request->user();

            $positionName = $user->position ? $user->position->name : '';
            $position = strtolower($positionName);

            $canApprove = strpos($position, 'captain') !== false ||
                          strpos($position, 'chairperson') !== false ||
                          strpos($position, 'barangay captain') !== false ||
                          strpos($position, 'sk chairperson') !== false;

            if (!$canApprove) {
                return response()->json([
                    'status' => false,
                    'message' => 'Only Captain/Chairperson can approve edit requests'
                ], 403);
            }

            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            if ($disbursement->status !== 'Edit Requested') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only disbursements with status "Edit Requested" can be approved'
                ], 400);
            }

            // Approval means returning to editable state (Unliquidated)
            $disbursement->status = 'Unliquidated';
            $disbursement->save();

            AdminAuthController::logUserAction(
                $user,
                'Approved Edit Request',
                sprintf('#%s edit request approved', $disbursement->dv_number)
            );

            return response()->json([
                'status' => true,
                'message' => 'Edit request approved',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error approving edit request: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to approve edit request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/disbursements/{id}/edit-reject
    public function rejectEdit(Request $request, $id)
    {
        try {
            $user = $request->user();

            $request->validate([
                'remarks' => 'required|string|max:500'
            ]);

            $positionName = $user->position ? $user->position->name : '';
            $position = strtolower($positionName);

            $canReject = strpos($position, 'captain') !== false ||
                         strpos($position, 'chairperson') !== false ||
                         strpos($position, 'barangay captain') !== false ||
                         strpos($position, 'sk chairperson') !== false;

            if (!$canReject) {
                return response()->json([
                    'status' => false,
                    'message' => 'Only Captain/Chairperson can reject edit requests'
                ], 403);
            }

            $disbursement = Disbursement::where('id', $id)
                ->where('barangay_id', $user->barangay_id)
                ->firstOrFail();

            if ($disbursement->status !== 'Edit Requested') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only disbursements with status "Edit Requested" can be rejected'
                ], 400);
            }

            // On reject, keep it Unliquidated and save rejection remarks
            $disbursement->status = 'Unliquidated';
            $disbursement->rejection_remarks = $request->remarks;
            $disbursement->save();

            AdminAuthController::logUserAction(
                $user,
                'Rejected Edit Request',
                sprintf('#%s edit request rejected', $disbursement->dv_number)
            );

            return response()->json([
                'status' => true,
                'message' => 'Edit request rejected',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error rejecting edit request: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to reject edit request',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    // GET /api/barangay/expense-details
    public function getExpenseDetails(Request $request)
    {
        try {
            $user = $request->user();

            $query = TranExpenseDetail::with(['appropriation.expenseClass', 'appropriation.expenseType', 'appropriation.expenseItem', 'appropriation.expenseSubItem', 'disbursement']);

            // Determine target barangay: allow explicit barangay_id (for admin), else fallback to user's barangay
            $targetBarangayId = $request->input('barangay_id');
            if (!$targetBarangayId && $user && isset($user->barangay_id)) {
                $targetBarangayId = $user->barangay_id;
            }

            if ($targetBarangayId) {
                $query->whereHas('appropriation', function($q) use ($targetBarangayId) {
                    $q->where('barangay_id', $targetBarangayId);
                });
            }

            // Optional filters for admin RAC preview
            $from = $request->input('from');
            $to = $request->input('to');
            $expenseClassId = $request->input('expense_class_id');

            if ($from && $to) {
                // normalize date format
                $fromDate = str_replace('/', '-', $from);
                $toDate = str_replace('/', '-', $to);
                $query->whereHas('disbursement', function($q) use ($fromDate, $toDate) {
                    $q->whereBetween('date', [$fromDate, $toDate]);
                });
            }

            if ($expenseClassId) {
                $query->whereHas('appropriation', function($q) use ($expenseClassId) {
                    $q->where('expense_class_id', $expenseClassId);
                });
            }

            $expenseDetails = $query->get();

            $result = $expenseDetails->map(function($detail) {
                $expenseClassName = optional($detail->appropriation->expenseClass)->name;
                $expenseTypeName = optional($detail->appropriation->expenseType)->name;
                $expenseItemName = optional($detail->appropriation->expenseItem)->name;

                // Compose an account title similar to barangay RAC (prefer item/type/class)
                $accountTitle = $expenseItemName ?: ($expenseTypeName ?: $expenseClassName);

                return [
                    'id' => $detail->id,
                    'disbursement_id' => $detail->disbursement_id,
                    'appropriation_id' => $detail->appropriation_id,
                    'amount' => (float) ($detail->amount ?? optional($detail->disbursement)->dv_amount ?? 0),
                    'particular' => $detail->particulars,
                    'particulars' => $detail->particulars,
                    'appropriation' => (float) optional($detail->appropriation)->amount,
                    'expense_class_id' => $detail->appropriation->expense_class_id ?? null,
                    'expense_type_id' => $detail->appropriation->expense_type_id ?? null,
                    'expense_item_id' => $detail->appropriation->expense_item_id ?? null,
                    'expense_sub_item_id' => $detail->appropriation->expense_sub_item_id ?? null,
                    'expense_class_name' => $expenseClassName,
                    'expense_class_order' => optional($detail->appropriation->expenseClass)->order,
                    'expense_type_name' => $expenseTypeName,
                    'expense_item_name' => $expenseItemName,
                    'expense_sub_item_name' => optional($detail->appropriation->expenseSubItem)->name,
                    'date' => optional($detail->disbursement)->date,
                    'dvNumber' => optional($detail->disbursement)->dv_number,
                    'dv_number' => optional($detail->disbursement)->dv_number,
                    'payee' => optional($detail->disbursement)->payee,
                    'accountTitle' => $accountTitle,
                    'created_at' => $detail->created_at,
                    'updated_at' => $detail->updated_at,
                ];
            });

            return response()->json([
                'status' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching expense details: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch expense details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/expense-details
    public function storeExpenseDetail(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'particulars' => 'nullable|string',
            'disbursement_id' => 'exists:disbursements,id', // Allow disbursement_id to be provided
            // Client may provide either a direct appropriation_id or one of the expense hierarchy IDs
            'appropriation_id' => 'nullable|exists:tran_appropriations,id',
            'expense_class_id' => 'nullable|exists:lib_expense_classes,id',
            'expense_type_id' => 'nullable|exists:lib_expense_types,id',
            'expense_item_id' => 'nullable|exists:lib_expense_items,id',
        ]);

        try {
            $user = $request->user();

            \Log::info('Starting expense detail creation:', [
                'user_id' => $user->id,
                'barangay_id' => $user->barangay_id,
                'request_data' => $request->all()
            ]);

            // Use database transaction to ensure data consistency
            DB::beginTransaction();

            // Resolve appropriation
            $appropriationQuery = TranAppropriation::where('barangay_id', $user->barangay_id)
                ->where('status', 'committed');

            $appropriation = null;
            if ($request->filled('appropriation_id')) {
                $appropriation = $appropriationQuery->where('id', $request->appropriation_id)->first();
            }

            if (!$appropriation) {
                // Prefer most specific ID first (item > type > class)
                if ($request->filled('expense_item_id')) {
                    $appropriationQuery->where('expense_item_id', $request->expense_item_id);
                } elseif ($request->filled('expense_type_id')) {
                    $appropriationQuery->whereNull('expense_item_id')
                        ->where('expense_type_id', $request->expense_type_id);
                } elseif ($request->filled('expense_class_id')) {
                    $appropriationQuery->whereNull('expense_item_id')
                        ->whereNull('expense_type_id')
                        ->where('expense_class_id', $request->expense_class_id);
                } else {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => 'No appropriation reference provided',
                        'errors' => ['appropriation' => ['Provide appropriation_id or one of expense_item_id/expense_type_id/expense_class_id']]
                    ], 422);
                }

                // Pick the most recent committed appropriation that matches
                $appropriation = $appropriationQuery->orderByDesc('created_at')->first();
                if (!$appropriation) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => 'No committed appropriation found for the selected account',
                    ], 422);
                }
            }

            \Log::info('Found appropriation:', [
                'id' => $appropriation->id,
                'amount' => $appropriation->amount,
                'expense_class_id' => $appropriation->expense_class_id,
                'expense_type_id' => $appropriation->expense_type_id,
                'expense_item_id' => $appropriation->expense_item_id,
            ]);

            // Find all matching appropriations and treat them as one budget pool
            $matchingAppropriationsQuery = TranAppropriation::where('barangay_id', $user->barangay_id)
                ->where('status', 'committed');

            if ($appropriation->expense_item_id) {
                $matchingAppropriationsQuery->where('expense_item_id', $appropriation->expense_item_id);
            } elseif ($appropriation->expense_type_id) {
                $matchingAppropriationsQuery->whereNull('expense_item_id')
                    ->where('expense_type_id', $appropriation->expense_type_id);
            } else {
                $matchingAppropriationsQuery->whereNull('expense_item_id')
                    ->where('expense_class_id', $appropriation->expense_class_id);
            }

            $matchingAppropriations = $matchingAppropriationsQuery->orderBy('created_at', 'asc')->get();

            \Log::info('Matching appropriations found:', [
                'count' => $matchingAppropriations->count(),
                'requested_amount' => $request->amount,
                'expense_class_id' => $request->expense_class_id,
                'expense_type_id' => $request->expense_type_id,
                'expense_item_id' => $request->expense_item_id,
            ]);

            if ($matchingAppropriations->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'No appropriations found for the selected account',
                ], 422);
            }

            // Calculate total available balance across all appropriations (treat as one pool)
            $totalAvailableBalance = 0;
            foreach ($matchingAppropriations as $appr) {
                $alreadyUsed = TranExpenseDetail::where('appropriation_id', $appr->id)->sum('amount');
                $available = max(0, (float)$appr->amount - (float)$alreadyUsed);
                $totalAvailableBalance += $available;
            }

            \Log::info('Total available balance:', [
                'total_available' => $totalAvailableBalance,
                'requested_amount' => $request->amount,
            ]);

            if ($totalAvailableBalance < (float)$request->amount) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient appropriation balance to cover requested amount',
                ], 422);
            }

            // Create the expense detail record
            $expenseDetail = TranExpenseDetail::create([
                'disbursement_id' => $request->disbursement_id, // Use provided disbursement_id or null
                'appropriation_id' => $matchingAppropriations->first()->id, // Use first appropriation as reference
                'amount' => (float)$request->amount,
                'particulars' => $request->particulars ?? '',
            ]);

            \Log::info('Successfully created expense detail:', [
                'id' => $expenseDetail->id,
                'amount' => $expenseDetail->amount,
                'appropriation_id' => $expenseDetail->appropriation_id,
                'disbursement_id' => $expenseDetail->disbursement_id,
            ]);

            // Commit the transaction
            DB::commit();

            // Return the expense detail
            return response()->json([
                'status' => true,
                'message' => 'Expense detail created successfully',
                'data' => [
                    'id' => $expenseDetail->id,
                    'disbursement_id' => $expenseDetail->disbursement_id,
                    'appropriation_id' => $expenseDetail->appropriation_id,
                    'amount' => $expenseDetail->amount,
                    'particulars' => $expenseDetail->particulars,
                    'expense_class_id' => $appropriation->expense_class_id,
                    'expense_type_id' => $appropriation->expense_type_id,
                    'expense_item_id' => $appropriation->expense_item_id,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating expense detail: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create expense detail',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // PATCH /api/barangay/expense-details/{id}
    public function updateExpenseDetail(Request $request, $id)
    {
        $request->validate([
            'disbursement_id' => 'nullable|exists:disbursements,id',
        ]);

        try {
            $user = $request->user();

            // Find the expense detail and ensure it belongs to the user's barangay
            $expenseDetail = TranExpenseDetail::where('id', $id)
                ->whereHas('appropriation', function($q) use ($user) {
                    $q->where('barangay_id', $user->barangay_id);
                })
                ->firstOrFail();

            $expenseDetail->update([
                'disbursement_id' => $request->disbursement_id,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Expense detail updated successfully',
                'data' => $expenseDetail
            ]);

        } catch (\Exception $e) {
            \Log::error('Error updating expense detail: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update expense detail',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // DELETE /api/barangay/expense-details/{id}
    public function destroyExpenseDetail(Request $request, $id)
    {
        try {
            $user = $request->user();

            // Find the expense detail and ensure it belongs to the user's barangay
            $expenseDetail = TranExpenseDetail::where('id', $id)
                ->whereHas('appropriation', function($q) use ($user) {
                    $q->where('barangay_id', $user->barangay_id);
                })
                ->firstOrFail();

            // Only allow deletion if disbursement_id is null (unsaved)
            if ($expenseDetail->disbursement_id !== null) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete expense detail that is already associated with a disbursement'
                ], 400);
            }

            $expenseDetail->delete();

            return response()->json([
                'status' => true,
                'message' => 'Expense detail deleted successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error deleting expense detail: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete expense detail',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generateDvNumber(Request $request)
    {
        $today = now();
        $dd = str_pad($today->day, 2, '0', STR_PAD_LEFT);
        $mm = str_pad($today->month, 2, '0', STR_PAD_LEFT);
        $yyyy = $today->year;

        $likePattern = 'DV-'.substr($yyyy, -2).'-'.$mm.'-%';

        $lastDisbursements = Disbursement::withoutGlobalScopes();
        $lastDisbursement=$lastDisbursements->where('dv_number', 'like', $likePattern)->orderByDesc('dv_number')->first();

        $lastSequence = 0;
        if ($lastDisbursement) {
            $dv = $lastDisbursement->dv_number;
            $segments = explode('-', $dv);
            if (count($segments) === 4) {
                $lastSegment = $segments[3];
                $lastSequence = (int)$lastSegment;
            }
        }
        $newDvNumber = sprintf('DV-%s-%s-%s', substr($yyyy, -2), $mm, str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT));
        return response()->json([
            'status' => true,
            'data' => [
                'date' => sprintf('%s/%s/%s', $dd, $mm, $yyyy),
                'dv_number' => $newDvNumber,
            ]
        ]);

    }

    /**
     * Calculate available budget for a given appropriation
     */
    private function calculateAvailableBudget($appropriationId)
    {
        try {
            // Get the appropriation
            $appropriation = TranAppropriation::find($appropriationId);
            if (!$appropriation) {
                \Log::error('Budget calculation: Appropriation not found', ['appropriation_id' => $appropriationId]);
                return 0;
            }

            // Get total disbursed amount for this appropriation
            $totalDisbursed = TranExpenseDetail::where('appropriation_id', $appropriationId)
                ->sum('amount');

            // Calculate available budget
            $availableBudget = $appropriation->amount - $totalDisbursed;

            \Log::info('Budget calculation details', [
                'appropriation_id' => $appropriationId,
                'appropriation_amount' => $appropriation->amount,
                'total_disbursed' => $totalDisbursed,
                'available_budget' => $availableBudget
            ]);

            return max(0, $availableBudget); // Ensure non-negative

        } catch (\Exception $e) {
            \Log::error('Error calculating available budget: ' . $e->getMessage(), [
                'appropriation_id' => $appropriationId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}
