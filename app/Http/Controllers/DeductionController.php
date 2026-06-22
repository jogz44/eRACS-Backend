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

            'divisor' => 'required|numeric',

            'vat_percent' => 'nullable|numeric',
            'ewt_percent' => 'nullable|numeric',

            'description' => 'nullable|string',

            'gross_vat_inc' => 'nullable|numeric',
            'gross_vat_exc' => 'nullable|numeric',

            'deduction_amount' => 'nullable|numeric'
        ]);

        $deduction = Deduction::create($validated);

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
