<?php

namespace App\Http\Controllers;

use App\Models\LibCheque;
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

        //STEP 1: Get Disbursement
        $disbursement = Disbursement::findOrFail(
            $validated['disbursement_id']
        );

        //STEP 2: Determine FINAL Available Amount
        $netAmount = (float) $disbursement->dv_amount;

        //STEP 4: Total Existing Cheques
        $totalChequeAmount = LibCheque::where(
            'disbursement_id',
            $validated['disbursement_id']
        )->sum('amount');

        //STEP 5: Remaining Balance
        $remainingBalance = round($netAmount - (float) $totalChequeAmount, 2);

        //STEP 6: Validate Cheque Amount
        if ($remainingBalance <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'The final DV amount has already been fully allocated to cheques.',
                'data' => [
                    'dv_amount'          => round($netAmount, 2),
                    'already_allocated'  => round($totalChequeAmount, 2),
                    'remaining_balance'  => 0,
                ]
            ], 422);
        }

        if ((float) $validated['amount'] > $remainingBalance) {
            return response()->json([
                'status' => false,
                'message' => 'Cheque amount exceeds the remaining final DV amount.',
                'data' => [
                    'dv_amount'          => round($netAmount, 2),
                    'already_allocated'  => round($totalChequeAmount, 2),
                    'remaining_balance'  => round($remainingBalance, 2),
                    'requested_amount'   => round((float) $validated['amount'], 2),
                ]
            ], 422);
        }

        //STEP 7: Get Next Available Cheque
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

        //STEP 8: Assign Cheque
        $chequeAmount = round(
            (float) $validated['amount'],
            2
        );

        $cheque->update([
            'disbursement_id' => $validated['disbursement_id'],
            'cheque_date'     => $validated['cheque_date'],
            'amount'          => $chequeAmount,
            'status'          => 'used',
        ]);

        //STEP 9: Return Response
        $newRemainingBalance = round(
            $remainingBalance - $chequeAmount,
            2
        );

        return response()->json([
            'status' => true,
            'message' => 'Cheque added successfully.',
            'data' => [
                'id'                => $cheque->id,
                'cheque_number'     => $cheque->cheque_number,
                'amount'            => $chequeAmount,

                // Final amount of the DV
                'dv_amount'         => round($netAmount, 2),

                // Remaining amount available for another cheque
                'remaining_balance' => $newRemainingBalance,
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
