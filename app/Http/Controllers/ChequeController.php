<?php

namespace App\Http\Controllers;

use App\Models\LibCheque;
use App\Models\Deduction;
use App\Models\TranExpenseDetail;
use App\Models\Disbursement;
use Illuminate\Http\Request;

class ChequeController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'disbursement_id' => 'required|exists:disbursements,id',
            'booklet_id'      => 'required|exists:lib_booklet,id',
            'cheque_date'     => 'required|date',
            'amount'          => 'required|numeric|min:0.01',
        ]);

        /*
        |--------------------------------------------------------------------------
        | STEP 1: Determine Available Net Amount
        |--------------------------------------------------------------------------
        */
        $lastDeduction = Deduction::where(
            'disbursement_id',
            $validated['disbursement_id']
        )
        ->latest('id')
        ->first();

        if ($lastDeduction) {

            // Use the final net amount after all deductions
            $netAmount = (float) $lastDeduction->net_amount;

        } else {

            // No deductions yet → use original expense total
            $netAmount = TranExpenseDetail::where(
                'disbursement_id',
                $validated['disbursement_id']
            )->sum('amount');
        }

        /*
        |--------------------------------------------------------------------------
        | STEP 2: Total Existing Cheques
        |--------------------------------------------------------------------------
        */
        $totalChequeAmount = LibCheque::where(
            'disbursement_id',
            $validated['disbursement_id']
        )->sum('amount');

        /*
        |--------------------------------------------------------------------------
        | STEP 3: Remaining Balance
        |--------------------------------------------------------------------------
        */
        $remainingBalance = $netAmount - $totalChequeAmount;

        /*
        |--------------------------------------------------------------------------
        | STEP 4: Validate Amount
        |--------------------------------------------------------------------------
        */
        if ($validated['amount'] > $remainingBalance) {

            return response()->json([
                'status' => false,
                'message' => 'Cheque amount exceeds remaining balance.',
                'data' => [
                    'net_amount'         => round($netAmount, 2),
                    'already_allocated'  => round($totalChequeAmount, 2),
                    'remaining_balance'  => round($remainingBalance, 2),
                ]
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | STEP 5: Get Next Available Cheque
        |--------------------------------------------------------------------------
        */
        $cheque = LibCheque::where(
                'booklet_id',
                $validated['booklet_id']
            )
            ->where(function ($query) {
                $query->whereNull('disbursement_id')
                      ->orWhere('status', 'unused');
            })
            ->orderBy('id')
            ->first();

        if (!$cheque) {

            return response()->json([
                'status' => false,
                'message' => 'No available cheques in this booklet.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | STEP 6: Assign Cheque
        |--------------------------------------------------------------------------
        */
        $cheque->update([
            'disbursement_id' => $validated['disbursement_id'],
            'cheque_date'     => $validated['cheque_date'],
            'amount'          => round($validated['amount'], 2),
            'status'          => 'used',
        ]);

        /*
        |--------------------------------------------------------------------------
        | STEP 7: Return Response
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'status' => true,
            'message' => 'Cheque added successfully.',
            'data' => [
                'id'                => $cheque->id,
                'cheque_number'     => $cheque->cheque_number,
                'amount'            => $cheque->amount,
                'net_amount'        => round($netAmount, 2),
                'remaining_balance' => round(
                    $remainingBalance - $validated['amount'],
                    2
                ),
            ]
        ]);
    }

    public function getByDisbursement($id)
    {
        $cheques = LibCheque::where(
            'disbursement_id',
            $id
        )
        ->with(['bank', 'booklet'])
        ->orderBy('cheque_date')
        ->get();

        return response()->json([
            'status' => true,
            'data' => $cheques
        ]);
    }

    public function destroy($id)
    {
        $cheque = LibCheque::findOrFail($id);

        $cheque->update([
            'disbursement_id' => null,
            'amount'          => null,
            'status'          => 'unused',
            'cheque_date'     => null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Cheque removed successfully.'
        ]);
    }
}
