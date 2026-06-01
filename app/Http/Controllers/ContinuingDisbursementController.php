<?php

namespace App\Http\Controllers;

use App\Models\ContDisbursement;
use App\Models\LibBooklet;
use App\Models\LibCheque;
use App\Models\ContDisbursementOrDetail;
use App\Models\ContTranExpenseDetail;
use App\Models\TranAppropriation;
use App\Models\ContApproAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AdminAuthController;

class ContinuingDisbursementController extends Controller
{
    // GET /api/barangay/continuing-disbursements
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ContDisbursement::where('barangay_id', $user->barangay_id)
            ->with(['bank', 'expenseDetails.contApproAccount.transactionAppropriation.expenseClass', 'expenseDetails.contApproAccount.transactionAppropriation.expenseType', 'expenseDetails.contApproAccount.transactionAppropriation.expenseItem']);

        $disbursements = $query->orderByDesc('created_at')->get();

        $formattedDisbursements = $disbursements->map(function ($disbursement) {
            return [
                'id' => $disbursement->id,
                'date' => $disbursement->date,
                'dvNumber' => $disbursement->dv_number,
                'chequeNumber' => $disbursement->cheque_number,
                'bank' => $disbursement->bank ? $disbursement->bank->bank_name : 'N/A',
                'bank_id' => $disbursement->bank_id,
                'payee' => $disbursement->payee,
                'payee2' => $disbursement->payee2,
                'dvAmount' => $disbursement->dv_amount,
                'status' => $disbursement->status,
                'expenses' => $disbursement->expenseDetails->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'accountId' => $detail->cont_appro_account_id,
                        'accountName' => $this->getAccountNameFromContApproAccountId($detail->cont_appro_account_id),
                        'particular' => $detail->particulars,
                        'amount' => $detail->amount,
                    ];
                }),
                'created_at' => $disbursement->created_at,
                'updated_at' => $disbursement->updated_at,
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $formattedDisbursements
        ]);
    }

    // POST /api/barangay/continuing-disbursements
    public function store(Request $request)
    {
        // Debug: Log the incoming request data
        \Log::info('Continuing Disbursement Store Request:', [
            'all_data' => $request->all(),
            'expenses' => $request->input('expenses', []),
        ]);

        try {
            $validated = $request->validate([
                'date' => 'required|date',
                'dvNumber' => 'required|string|max:255',
                'chequeNumber' => 'required|string|max:255',
                'bank_id' => 'required|exists:lib_banks,id',
                'payee' => 'required|string|max:255',
                'payee2' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0',
                'expenses' => 'required|array|min:1',
                'expenses.*.accountId' => 'required|exists:cont_appro_accounts,id',
                'expenses.*.particulars' => 'required|string|max:255',
                'expenses.*.amount' => 'required|numeric|min:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation failed:', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create the disbursement
            $disbursement = ContDisbursement::create([
                'barangay_id' => $request->user()->barangay_id,
                'date' => $validated['date'],
                'dv_number' => $validated['dvNumber'],
                'cheque_number' => $validated['chequeNumber'],
                'bank_id' => $validated['bank_id'],
                'payee' => $validated['payee'],
                'payee2' => $validated['payee2'],
                'dv_amount' => $validated['amount'],
                'status' => 'Unliquidated',
                'user_id' => $request->user()->id,
            ]);

            // Create expense details
            foreach ($validated['expenses'] as $expense) {
                // Get the continuing appropriation account
                $contAccount = ContApproAccounts::find($expense['accountId']);
                if (!$contAccount) {
                    throw new \Exception("Continuing appropriation account not found");
                }

                // Create expense detail
                ContTranExpenseDetail::create([
                    'cont_disbursement_id' => $disbursement->id,
                    'cont_appro_account_id' => $contAccount->id, // Use the continuing account ID directly
                    'particulars' => $expense['particulars'],
                    'amount' => $expense['amount'],
                ]);

                // Update the current amount of the continuing appropriation account
                $contAccount->current_amount -= $expense['amount'];
                $contAccount->save();
            }

            // Update cheque status
            $cheque = LibCheque::where('cheque_number', $validated['chequeNumber'])->first();
            if ($cheque) {
                $cheque->status = 'used';
                $cheque->disbursement_id = $disbursement->id;
                $cheque->save();
            }

            DB::commit();

            // Log the action
            AdminAuthController::logUserAction(
                $request->user(),
                'Created Continuing Disbursement',
                "Created continuing disbursement DV-{$disbursement->dv_number} for {$disbursement->payee}"
            );

            return response()->json([
                'status' => true,
                'message' => 'Continuing disbursement created successfully',
                'data' => $disbursement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to create continuing disbursement: ' . $e->getMessage()
            ], 500);
        }
    }

    // GET /api/barangay/continuing-disbursements/{id}
    public function show(Request $request, $id)
    {
        $disbursement = ContDisbursement::where('id', $id)
            ->where('barangay_id', $request->user()->barangay_id)
            ->with(['bank', 'expenseDetails.contApproAccount.transactionAppropriation.expenseClass', 'expenseDetails.contApproAccount.transactionAppropriation.expenseType', 'expenseDetails.contApproAccount.transactionAppropriation.expenseItem'])
            ->first();

        if (!$disbursement) {
            return response()->json([
                'status' => false,
                'message' => 'Continuing disbursement not found'
            ], 404);
        }

        $formattedDisbursement = [
            'id' => $disbursement->id,
            'date' => $disbursement->date,
            'dvNumber' => $disbursement->dv_number,
            'chequeNumber' => $disbursement->cheque_number,
            'bank' => $disbursement->bank ? $disbursement->bank->bank_name : 'N/A',
            'bank_id' => $disbursement->bank_id,
            'payee' => $disbursement->payee,
            'payee2' => $disbursement->payee2,
            'dvAmount' => $disbursement->dv_amount,
            'status' => $disbursement->status,
            'expenses' => $disbursement->expenseDetails->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'accountId' => $detail->cont_appro_account_id,
                    'accountName' => $this->getAccountNameFromContApproAccountId($detail->cont_appro_account_id),
                    'particular' => $detail->particulars,
                    'amount' => $detail->amount,
                ];
            }),
            'created_at' => $disbursement->created_at,
            'updated_at' => $disbursement->updated_at,
        ];

        return response()->json([
            'status' => true,
            'data' => $formattedDisbursement
        ]);
    }

    // PUT /api/barangay/continuing-disbursements/{id}
    public function update(Request $request, $id)
    {
        $disbursement = ContDisbursement::where('id', $id)
            ->where('barangay_id', $request->user()->barangay_id)
            ->first();

        if (!$disbursement) {
            return response()->json([
                'status' => false,
                'message' => 'Continuing disbursement not found'
            ], 404);
        }

        $validated = $request->validate([
            'date' => 'required|date',
            'dvNumber' => 'required|string|max:255',
            'chequeNumber' => 'required|string|max:255',
            'bank_id' => 'required|exists:lib_banks,id',
            'payee' => 'required|string|max:255',
            'payee2' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'expenses' => 'required|array|min:1',
            'expenses.*.accountId' => 'required|exists:cont_appro_accounts,id',
            'expenses.*.particulars' => 'required|string|max:255',
            'expenses.*.amount' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            // Update the disbursement
            $disbursement->update([
                'date' => $validated['date'],
                'dv_number' => $validated['dvNumber'],
                'cheque_number' => $validated['chequeNumber'],
                'bank_id' => $validated['bank_id'],
                'payee' => $validated['payee'],
                'payee2' => $validated['payee2'],
                'dv_amount' => $validated['amount'],
            ]);

            // Delete existing expense details
            ContTranExpenseDetail::where('cont_disbursement_id', $disbursement->id)->delete();

            // Create new expense details
            foreach ($validated['expenses'] as $expense) {
                // Get the continuing appropriation account
                $contAccount = ContApproAccounts::find($expense['accountId']);
                if (!$contAccount) {
                    throw new \Exception("Continuing appropriation account not found");
                }

                // Create expense detail
                ContTranExpenseDetail::create([
                    'cont_disbursement_id' => $disbursement->id,
                    'cont_appro_account_id' => $contAccount->id,
                    'particulars' => $expense['particulars'],
                    'amount' => $expense['amount'],
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Continuing disbursement updated successfully',
                'data' => $disbursement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to update continuing disbursement: ' . $e->getMessage()
            ], 500);
        }
    }

    // DELETE /api/barangay/continuing-disbursements/{id}
    public function destroy(Request $request, $id)
    {
        $disbursement = ContDisbursement::where('id', $id)
            ->where('barangay_id', $request->user()->barangay_id)
            ->first();

        if (!$disbursement) {
            return response()->json([
                'status' => false,
                'message' => 'Continuing disbursement not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Restore current amounts
            $expenseDetails = ContTranExpenseDetail::where('cont_disbursement_id', $disbursement->id)->get();
            foreach ($expenseDetails as $detail) {
                // Find the continuing appropriation account
                $contAccount = ContApproAccounts::find($detail->cont_appro_account_id);
                if ($contAccount) {
                    $contAccount->current_amount += $detail->amount;
                    $contAccount->save();
                }
            }

            // Delete expense details
            ContTranExpenseDetail::where('cont_disbursement_id', $disbursement->id)->delete();

            // Update cheque status
            $cheque = LibCheque::where('cheque_number', $disbursement->cheque_number)->first();
            if ($cheque) {
                $cheque->status = 'unused';
                $cheque->disbursement_id = null;
                $cheque->save();
            }

            // Delete the disbursement
            $disbursement->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Continuing disbursement deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete continuing disbursement: ' . $e->getMessage()
            ], 500);
        }
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

    /**
     * Get OR details for a continuing disbursement
     */
    public function getOrDetails(Request $request, $id)
    {
        try {
            $disbursement = ContDisbursement::where('barangay_id', $request->user()->barangay_id)
                ->findOrFail($id);

            $orDetails = ContDisbursementOrDetail::where('cont_disbursement_id', $id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($orDetail) {
                    // Convert date from YYYY-MM-DD to DD/MM/YYYY format for frontend
                    $formattedDate = '';
                    if ($orDetail->or_date) {
                        $date = \Carbon\Carbon::parse($orDetail->or_date);
                        $formattedDate = $date->format('d/m/Y');
                    }

                    return [
                        'id' => $orDetail->id,
                        'orDate' => $formattedDate,
                        'orNumber' => $orDetail->or_number,
                        'orAmount' => $orDetail->or_amount,
                        'orPhotoUrl' => $orDetail->or_photo,
                        'remarks' => $orDetail->remarks,
                        'created_at' => $orDetail->created_at,
                        'updated_at' => $orDetail->updated_at,
                    ];
                });

            return response()->json([
                'status' => true,
                'data' => $orDetails
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch OR details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save OR details for continuing disbursement
     */
    public function saveOrDetails(Request $request, $id)
    {
        $validated = $request->validate([
            'orDetails' => 'required|array',
            'orDetails.*.orNumber' => 'required|string',
            'orDetails.*.orAmount' => 'required|numeric|min:0',
            'orDetails.*.orDate' => 'required|string',
            'orDetails.*.remarks' => 'nullable|string',
            'orDetails.*.orPhotoUrl' => 'nullable|string',
            'liquidatedAmount' => 'required|numeric|min:0',
            'isPartial' => 'required|boolean'
        ]);

        try {
            DB::beginTransaction();

            $disbursement = ContDisbursement::where('barangay_id', $request->user()->barangay_id)
                ->findOrFail($id);

            // Delete existing OR details
            ContDisbursementOrDetail::where('cont_disbursement_id', $id)->delete();

            // Create new OR details
            foreach ($validated['orDetails'] as $orDetail) {
                // Convert date from DD/MM/YYYY to YYYY-MM-DD format
                $orDate = null;
                if ($orDetail['orDate']) {
                    $dateParts = explode('/', $orDetail['orDate']);
                    if (count($dateParts) === 3) {
                        $orDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                    }
                }

                ContDisbursementOrDetail::create([
                    'cont_disbursement_id' => $id,
                    'or_date' => $orDate,
                    'or_number' => $orDetail['orNumber'],
                    'or_amount' => $orDetail['orAmount'],
                    'or_photo' => $orDetail['orPhotoUrl'] ?? null,
                    'remarks' => $orDetail['remarks'] ?? null,
                ]);
            }

            // Update disbursement status and liquidated amount
            $status = $validated['isPartial'] ? 'Partial' : 'Liquidated';
            $disbursement->update([
                'status' => $status,
                'liquidated_amount' => $validated['liquidatedAmount'],
                'liquidated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'OR details saved successfully',
                'data' => $disbursement->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to save OR details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete OR detail
     */
    public function deleteOrDetail(Request $request, $disbursementId, $orDetailId)
    {
        try {
            $orDetail = ContDisbursementOrDetail::whereHas('contDisbursement', function($query) use ($request) {
                $query->where('barangay_id', $request->user()->barangay_id);
            })->findOrFail($orDetailId);

            $orDetail->delete();

            return response()->json([
                'status' => true,
                'message' => 'OR detail deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete OR detail: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload OR photo for continuing disbursements
     */
    public function uploadOrPhoto(Request $request)
    {
        try {
            $request->validate([
                'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
            ]);

            $file = $request->file('photo');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('or-photos', $filename, 'public');

            return response()->json([
                'status' => true,
                'path' => $path,
                'message' => 'Photo uploaded successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload photo: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getAccountNameFromContApproAccountId($contApproAccountId)
    {
        $contAccount = ContApproAccounts::with(['transactionAppropriation.expenseClass', 'transactionAppropriation.expenseType', 'transactionAppropriation.expenseItem'])->find($contApproAccountId);
        if (!$contAccount || !$contAccount->transactionAppropriation) {
            return 'Unknown Account';
        }
        $appr = $contAccount->transactionAppropriation;
        $parts = [];
        if ($appr->expenseClass) { $parts[] = $appr->expenseClass->name; }
        if ($appr->expenseType) { $parts[] = $appr->expenseType->name; }
        if ($appr->expenseItem) { $parts[] = $appr->expenseItem->name; }
        return implode(' > ', $parts) ?: 'Unknown Account';
    }
}
