<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Deduction;
use App\Models\Disbursement;

class DeductionController extends Controller
{
    public function index()
    {
        return response()->json(Deduction::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'disbursement_id' => 'nullable|exists:disbursements,id',

            'deduction_type' => 'required|string',
            'tax_type' => 'nullable|string',
            'code' => 'nullable|string',

            'divisor' => 'nullable|numeric|min:1',

            'vat_percent' => 'nullable|numeric|min:0',
            'ewt_percent' => 'nullable|numeric|min:0',

            'description' => 'nullable|string',

            // User enters amount only
            'gross_vat_inc' => 'required|numeric|min:0',
        ]);

        $amount =
            (float) $validated['gross_vat_inc'];

        $divisor =
            (float) ($validated['divisor'] ?? 1);

        $vat =
            (float) ($validated['vat_percent'] ?? 0);

        $ewt =
            (float) ($validated['ewt_percent'] ?? 0);

        /*
        COMPUTE
        */

        $vatDeduction =
            ($amount / $divisor)
            * ($vat / 100);

        $ewtDeduction =
            ($amount / $divisor)
            * ($ewt / 100);

        $grossVatExc =
            $amount - $vatDeduction;

        $deductionAmount =
            $vatDeduction + $ewtDeduction;

        $netAmount =
            $amount - $deductionAmount;

        $validated['gross_vat_exc'] =
            round($grossVatExc, 2);

        $validated['deduction_amount'] =
            round($deductionAmount, 2);

        /*
        OPTIONAL
        */

        $validated['net_amount'] =
            round($netAmount, 2);

        $deduction =
            Deduction::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Deduction created',
            'data' => $deduction
        ]);
    }

    public function show($id)
    {
        return Deduction::findOrFail($id);
    }

    public function preview(Request $request)
    {
        $validated = $request->validate([

            'gross_vat_inc' => 'required|numeric|min:0',
            'divisor' => 'nullable|numeric|min:1',
            'vat_percent' => 'nullable|numeric|min:0',
            'ewt_percent' => 'nullable|numeric|min:0',

        ]);

        $amount = (float) $validated['gross_vat_inc'];
        $divisor = (float) ( $validated['divisor'] ?? 1 );
        $vat = (float) ( $validated['vat_percent'] ?? 0 );
        $ewt = (float) ( $validated['ewt_percent'] ?? 0 );
        $vatDeduction = ($amount / $divisor) * ($vat / 100);
        $ewtDeduction = ($amount / $divisor) * ($ewt / 100);
        $grossVatExc = $amount - $vatDeduction;
        $deductionAmount = $vatDeduction + $ewtDeduction;
        $netAmount = $amount - $deductionAmount;

        return response()->json([
            'status' => true,
            'data' => [
                'gross_vat_inc' => round( $amount, 2 ),
                'gross_vat_exc' => round( $grossVatExc, 2 ),
                'deduction_amount' => round( $deductionAmount, 2 ),
                'net_amount' => round( $netAmount, 2 ),
            ]
        ]);
    }

    public function update(Request $request, $id)
    {
        $deduction = Deduction::findOrFail($id);

        $deduction->update($request->all());

        return response()->json([
            'status' => true,
            'message' => 'Updated',
            'data' => $deduction
        ]);
    }

    public function destroy($id)
    {
        Deduction::destroy($id);

        return response()->json([
            'status' => true,
            'message' => 'Deleted'
        ]);
    }

    public function getByDisbursement($id)
    {
        $disbursement = Disbursement::withoutGlobalScopes()
            ->with('deductions')
            ->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => [
                'disbursement_id' => $disbursement->id,
                'dv_number' => $disbursement->dv_number,
                'payee' => $disbursement->payee,
                'payee2' => $disbursement->payee2,
                'deductions' => $disbursement->deductions
            ]
        ]);
    }
}
