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
use App\Models\ContDeduction;
use App\Models\ContBankCheque;
use App\Models\Admin;
use App\Models\LibDeductionCode;

class ContinuingDisbursementController extends Controller
{
    // GET /api/barangay/continuing-disbursements
    public function index(Request $request)
    {
        $user = $request->user();

       $query = ContDisbursement::withoutGlobalScope(BarangayScope::class)
            ->with([
                'expenseDetails.contApproAccount.transactionAppropriation.expenseClass',
                'expenseDetails.contApproAccount.transactionAppropriation.expenseType',
                'expenseDetails.contApproAccount.transactionAppropriation.expenseItem',
                'deductions',
                'bankCheques.bank',
            ]);

        if (!($user instanceof Admin)) {
            $query->where('barangay_id', $user->barangay_id);
        }

        // Filter by year if provided
        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        }

        $disbursements = $query
            ->orderByDesc('created_at')
            ->get();

        $formattedDisbursements = $disbursements->map(function ($disbursement) {

            return [
                'id' => $disbursement->id,
                'date' => $disbursement->date,
                'dvNumber' => $disbursement->dv_number,

                'payee' => $disbursement->payee,
                'payee2' => $disbursement->payee2,

                'dvAmount' => $disbursement->dv_amount,

                'bank_status' => $disbursement->bank_status,

                'status' => $disbursement->status,

                'expenses' => $disbursement->expenseDetails->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'accountId' => $detail->cont_appro_account_id,
                        'accountName' => $this->getAccountNameFromContApproAccountId(
                            $detail->cont_appro_account_id
                        ),
                        'particular' => $detail->particulars,
                        'amount' => $detail->amount,
                    ];
                }),

                'deductions' => $disbursement->deductions->map(function ($deduction) {
                    return [
                        'id' => $deduction->id,
                        'deduction_code_id' => $deduction->deduction_code_id,
                        'deduction_type' => $deduction->deduction_type,
                        'tax_type' => $deduction->tax_type,
                        'code' => $deduction->code,
                        'description' => $deduction->description,
                        'divisor' => $deduction->divisor,
                        'vat_percent' => $deduction->vat_percent,
                        'ewt_percent' => $deduction->ewt_percent,
                        'gross_vat_inc' => $deduction->gross_vat_inc,
                        'gross_vat_exc' => $deduction->gross_vat_exc,
                        'deduction_amount' => $deduction->deduction_amount,
                        'net_amount' => $deduction->net_amount,
                    ];
                }),

                'bank_cheques' => $disbursement->bankCheques->map(function ($cheque) {
                    return [
                        'id' => $cheque->id,
                        'bank_id' => $cheque->bank_id,
                        'bank' => optional($cheque->bank)->bank_name,
                        'cheque_number' => $cheque->cheque_number,
                        'cheque_date' => $cheque->cheque_date,
                        'amount' => $cheque->amount,
                    ];
                }),

                // Optional helper for frontend
                'net_amount' => $disbursement->deductions->last()->net_amount ?? $disbursement->dv_amount,

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
        \Log::info('Continuing Disbursement Store Request:', [
            'all_data' => $request->all(),
            'expenses' => $request->input('expenses', []),
            'deductions' => $request->input('deductions', []),
            'bank_cheques' => $request->input('bank_cheques', []),
        ]);

        try {
            $validated = $request->validate([
                'date' => 'required|date',
                'dvNumber' => 'required|string|max:255',
                'payee' => 'required|string|max:255',
                'payee2' => 'nullable|string|max:255',
                'amount' => 'required|numeric|min:0',
                'bank_status' => 'required|in:online,offline',

                // Expenses
                'expenses' => 'required|array|min:1',
                'expenses.*.accountId' => 'required|exists:cont_appro_accounts,id',
                'expenses.*.particulars' => 'required|string|max:255',
                'expenses.*.amount' => 'required|numeric|min:0',

                // Deductions
                'deductions' => 'nullable|array',
                'deductions.*.deduction_code_id' => 'nullable|exists:lib_deduction_codes,id',

                'deductions.*.gross_vat_inc' => 'required|numeric',
                'deductions.*.gross_vat_exc' => 'nullable|numeric',
                'deductions.*.deduction_amount' => 'required|numeric',
                'deductions.*.net_amount' => 'required|numeric',

                // Multiple bank cheques
                'bank_cheques' => 'nullable|array',
                'bank_cheques.*.bank_id' => 'required|exists:lib_banks,id',
                'bank_cheques.*.cheque_number' => 'required|string',
                'bank_cheques.*.cheque_date' => 'required|date',
                'bank_cheques.*.amount' => 'required|numeric|min:0',
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

            // Create main disbursement
            $disbursement = ContDisbursement::create([
                'barangay_id' => $request->user()->barangay_id,
                'date' => $validated['date'],
                'dv_number' => $validated['dvNumber'],
                'payee' => $validated['payee'],
                'payee2' => $validated['payee2'] ?? null,
                'dv_amount' => $validated['amount'],

                'bank_status' => $validated['bank_status'],

                'status' => 'Unliquidated',
                'user_id' => $request->user()->id,
            ]);

            //expenses
            foreach ($validated['expenses'] as $expense) {

                $contAccount = ContApproAccounts::find(
                    $expense['accountId']
                );

                if (!$contAccount) {
                    throw new \Exception(
                        "Continuing appropriation account not found"
                    );
                }

                ContTranExpenseDetail::create([
                    'cont_disbursement_id' => $disbursement->id,
                    'cont_appro_account_id' => $contAccount->id,
                    'particulars' => $expense['particulars'],
                    'amount' => $expense['amount'],
                ]);

                $contAccount->current_amount -= $expense['amount'];
                $contAccount->save();
            }

            //Deductions
            foreach ($validated['deductions'] ?? [] as $deduction) {

                $libDeduction = LibDeductionCode::find($deduction['deduction_code_id']);

                ContDeduction::create([

                    'cont_disbursement_id' => $disbursement->id,

                    'deduction_code_id' => $libDeduction?->id,

                    'deduction_type' => $libDeduction?->deduction_type,
                    'tax_type'       => $libDeduction?->tax_type,
                    'code'           => $libDeduction?->code,
                    'description'    => $libDeduction?->label,
                    'divisor'        => $libDeduction?->divisor,
                    'vat_percent'    => $libDeduction?->vat_percent,
                    'ewt_percent'    => $libDeduction?->ewt_percent,

                    'gross_vat_inc'  => $deduction['gross_vat_inc'],
                    'gross_vat_exc'  => $deduction['gross_vat_exc'] ?? null,

                    'deduction_amount' => $deduction['deduction_amount'],
                    'net_amount'       => $deduction['net_amount'],
                ]);
            }

            //Multiple Bank Cheques
            foreach ($validated['bank_cheques'] ?? [] as $cheque) {

                ContBankCheque::create([
                    'cont_disbursement_id' => $disbursement->id,

                    'bank_id' => $cheque['bank_id'],
                    'cheque_number' => $cheque['cheque_number'],
                    'cheque_date' => $cheque['cheque_date'],
                    'amount' => $cheque['amount'],
                ]);

                // Mark cheque as used in library
                $libCheque = LibCheque::where(
                    'cheque_number',
                    $cheque['cheque_number']
                )->first();

                if ($libCheque) {
                    $libCheque->status = 'used';
                    $libCheque->disbursement_id = $disbursement->id;
                    $libCheque->save();
                }
            }

            DB::commit();

            AdminAuthController::logUserAction(
                $request->user(),
                'Created Continuing Disbursement',
                "Created continuing disbursement DV-{$disbursement->dv_number} for {$disbursement->payee}"
            );

            return response()->json([
                'status' => true,
                'message' => 'Continuing disbursement created successfully',
                'data' => $disbursement->load([
                    'expenseDetails',
                    'deductions',
                    'bankCheques'
                ])
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
        $user = $request->user();

        $query = ContDisbursement::withoutGlobalScope(BarangayScope::class)
            ->with([
                'expenseDetails.contApproAccount.transactionAppropriation.expenseClass',
                'expenseDetails.contApproAccount.transactionAppropriation.expenseType',
                'expenseDetails.contApproAccount.transactionAppropriation.expenseItem',
                'deductions',
                'bankCheques.bank',
            ]);

        if (!($user instanceof Admin)) {
            $query->where('barangay_id', $user->barangay_id);
        }

        $disbursement = $query->find($id);

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

            'payee' => $disbursement->payee,
            'payee2' => $disbursement->payee2,

            'dvAmount' => $disbursement->dv_amount,

            'bank_status' => $disbursement->bank_status,

            'status' => $disbursement->status,

            'expenses' => $disbursement->expenseDetails->map(function ($detail) {

                return [
                    'id' => $detail->id,
                    'accountId' => $detail->cont_appro_account_id,
                    'accountName' => $this->getAccountNameFromContApproAccountId(
                        $detail->cont_appro_account_id
                    ),
                    'particular' => $detail->particulars,
                    'amount' => $detail->amount,
                ];

            }),

            // NEW
            'deductions' => $disbursement->deductions->map(function ($deduction) {

                return [

                    'id' => $deduction->id,
                    'deduction_code_id' => $deduction->deduction_code_id,
                    'deduction_type' => $deduction->deduction_type,
                    'tax_type' => $deduction->tax_type,
                    'code' => $deduction->code,
                    'description' => $deduction->description,
                    'divisor' => $deduction->divisor,
                    'vat_percent' => $deduction->vat_percent,
                    'ewt_percent' => $deduction->ewt_percent,
                    'gross_vat_inc' => $deduction->gross_vat_inc,
                    'gross_vat_exc' => $deduction->gross_vat_exc,
                    'deduction_amount' => $deduction->deduction_amount,
                    'net_amount' => $deduction->net_amount,

                ];
            }),

            // NEW
            'bank_cheques' => $disbursement->bankCheques->map(function ($cheque) {
                return [

                    'id' => $cheque->id,
                    'bank_id' => $cheque->bank_id,
                    'bank' => optional($cheque->bank)->bank_name,
                    'cheque_number' => $cheque->cheque_number,
                    'cheque_date' => $cheque->cheque_date,
                    'amount' => $cheque->amount,

                ];
            }),

            'net_amount' => $disbursement->deductions->last()->net_amount ?? $disbursement->dv_amount,

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
            ->with([
                'expenseDetails',
                'deductions',
                'bankCheques'
            ])
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
            'payee' => 'required|string|max:255',
            'payee2' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
            'bank_status' => 'required|in:online,offline',

            // Expenses
            'expenses' => 'required|array|min:1',
            'expenses.*.accountId' => 'required|exists:cont_appro_accounts,id',
            'expenses.*.particulars' => 'required|string|max:255',
            'expenses.*.amount' => 'required|numeric|min:0',

            // Deductions
            'deductions' => 'nullable|array',
            'deductions.*.deduction_code_id' => 'nullable|exists:lib_deduction_codes,id',
            'deductions.*.deduction_type' => 'required|string',
            'deductions.*.tax_type' => 'nullable|string',
            'deductions.*.code' => 'nullable|string',
            'deductions.*.description' => 'nullable|string',
            'deductions.*.divisor' => 'nullable|numeric',
            'deductions.*.vat_percent' => 'nullable|numeric',
            'deductions.*.ewt_percent' => 'nullable|numeric',
            'deductions.*.gross_vat_inc' => 'required|numeric',
            'deductions.*.gross_vat_exc' => 'nullable|numeric',
            'deductions.*.deduction_amount' => 'required|numeric',
            'deductions.*.net_amount' => 'required|numeric',

            // Multiple Cheques
            'bank_cheques' => 'nullable|array',
            'bank_cheques.*.bank_id' => 'required|exists:lib_banks,id',
            'bank_cheques.*.cheque_number' => 'required|string',
            'bank_cheques.*.cheque_date' => 'required|date',
            'bank_cheques.*.amount' => 'required|numeric|min:0',

        ]);

        try {

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Restore Previous Appropriation Amounts
            |--------------------------------------------------------------------------
            */

            foreach ($disbursement->expenseDetails as $detail) {

                $account = ContApproAccounts::find(
                    $detail->cont_appro_account_id
                );

                if ($account) {

                    $account->current_amount += $detail->amount;
                    $account->save();

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Restore Previous Library Cheques
            |--------------------------------------------------------------------------
            */

            foreach ($disbursement->bankCheques as $oldCheque) {

                $libCheque = LibCheque::where(
                    'cheque_number',
                    $oldCheque->cheque_number
                )->first();

                if ($libCheque) {

                    $libCheque->update([
                        'status' => 'unused',
                        'disbursement_id' => null,
                    ]);

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Delete Old Child Records
            |--------------------------------------------------------------------------
            */

            ContTranExpenseDetail::where(
                'cont_disbursement_id',
                $disbursement->id
            )->delete();

            ContDeduction::where(
                'cont_disbursement_id',
                $disbursement->id
            )->delete();

            ContBankCheque::where(
                'cont_disbursement_id',
                $disbursement->id
            )->delete();

            /*
            |--------------------------------------------------------------------------
            | Update Main Record
            |--------------------------------------------------------------------------
            */

            $disbursement->update([

                'date' => $validated['date'],
                'dv_number' => $validated['dvNumber'],
                'payee' => $validated['payee'],
                'payee2' => $validated['payee2'] ?? null,
                'dv_amount' => $validated['amount'],
                'bank_status' => $validated['bank_status'],

            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Expenses
            |--------------------------------------------------------------------------
            */

            foreach ($validated['expenses'] as $expense) {

                $account = ContApproAccounts::find(
                    $expense['accountId']
                );

                if (!$account) {

                    throw new \Exception(
                        'Continuing appropriation account not found.'
                    );
                }

                ContTranExpenseDetail::create([
                    'cont_disbursement_id' => $disbursement->id,
                    'cont_appro_account_id' => $account->id,
                    'particulars' => $expense['particulars'],
                    'amount' => $expense['amount'],
                ]);

                $account->current_amount -= $expense['amount'];
                $account->save();

            }

            /*
            |--------------------------------------------------------------------------
            | Save Deductions
            |--------------------------------------------------------------------------
            */

            foreach ($validated['deductions'] ?? [] as $deduction) {

                $libDeduction = LibDeductionCode::find($deduction['deduction_code_id']);

                ContDeduction::create([

                    'cont_disbursement_id' => $disbursement->id,

                    'deduction_code_id' => $libDeduction?->id,

                    'deduction_type' => $libDeduction?->deduction_type,
                    'tax_type'       => $libDeduction?->tax_type,
                    'code'           => $libDeduction?->code,
                    'description'    => $libDeduction?->label,
                    'divisor'        => $libDeduction?->divisor,
                    'vat_percent'    => $libDeduction?->vat_percent,
                    'ewt_percent'    => $libDeduction?->ewt_percent,

                    'gross_vat_inc'  => $deduction['gross_vat_inc'],
                    'gross_vat_exc'  => $deduction['gross_vat_exc'] ?? null,

                    'deduction_amount' => $deduction['deduction_amount'],
                    'net_amount'       => $deduction['net_amount'],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Save Bank Cheques
            |--------------------------------------------------------------------------
            */

            foreach ($validated['bank_cheques'] ?? [] as $cheque) {

                ContBankCheque::create([

                    'cont_disbursement_id' => $disbursement->id,
                    'bank_id' => $cheque['bank_id'],
                    'cheque_number' => $cheque['cheque_number'],
                    'cheque_date' => $cheque['cheque_date'],
                    'amount' => $cheque['amount'],

                ]);

                $libCheque = LibCheque::where(
                    'cheque_number',
                    $cheque['cheque_number']
                )->first();

                if ($libCheque) {

                    $libCheque->update([

                        'status' => 'used',
                        'disbursement_id' => $disbursement->id,

                    ]);
                }
            }

            DB::commit();

            AdminAuthController::logUserAction(
                $request->user(),
                'Updated Continuing Disbursement',
                "Updated continuing disbursement DV-{$disbursement->dv_number} for {$disbursement->payee}"
            );

            return response()->json([
                'status' => true,
                'message' => 'Continuing disbursement updated successfully.',
                'data' => $disbursement->load([
                    'expenseDetails',
                    'deductions',
                    'bankCheques'
                ])
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([

                'status' => false,
                'message' => 'Failed to update continuing disbursement: '.$e->getMessage()

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

            $query = ContDisbursement::query();

            // Barangay users can only access their own records.
            // Admins can access any record.
            if (!($request->user() instanceof Admin)) {
                $query->where('barangay_id', $request->user()->barangay_id);
            }

            $disbursement = $query->findOrFail($id);

            $orDetails = ContDisbursementOrDetail::where(
                'cont_disbursement_id',
                $disbursement->id
            )
            ->orderBy('created_at')
            ->get()
            ->map(function ($orDetail) {

                return [
                    'id' => $orDetail->id,
                    'orDate' => $orDetail->or_date
                        ? \Carbon\Carbon::parse($orDetail->or_date)->format('d/m/Y')
                        : '',
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
                'message' => 'Failed to fetch OR details: '.$e->getMessage()
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
