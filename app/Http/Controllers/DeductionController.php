<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Disbursement;
use App\Models\Deduction;
use App\Models\LibDeductionCode;

class DeductionController extends Controller
{
    public function index()
    {
        //return response()->json(Deduction::all());
        return response()->json([
            'status' => true,
            'data' => Deduction::with('deductionCode')->get()
        ]);
    }

    public function store(Request $request)
    {
        \Log::info('REQUEST DATA', $request->all());

        $validated = $request->validate([
            'disbursement_id' => 'nullable|exists:disbursements,id',

            'deduction_code_id' => 'required|exists:lib_deduction_codes,id',

            'description' => 'nullable|string',

            // User enters amount only
            'gross_vat_inc' => 'required|numeric|min:0',
        ]);

        // Fetch deduction template
        $libCode = LibDeductionCode::findOrFail(
            $validated['deduction_code_id']
        );

        $grossVatInc = (float) $validated['gross_vat_inc'];
        if (!empty($validated['disbursement_id'])) {

            $lastDeduction = Deduction::where(
                'disbursement_id',
                $validated['disbursement_id']
            )
            ->latest('id')
            ->first();

            if ($lastDeduction) {
                $grossVatInc = (float) $lastDeduction->net_amount;
            }
        }

        $divisor = (float) ($libCode->divisor ?: 1);
        if ($divisor <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid divisor value.'
            ], 422);
        }
        $vat = (float) ($libCode->vat_percent ?? 0);
        $ewt = (float) ($libCode->ewt_percent ?? 0);

        // STEP 1: Remove VAT from the gross amount
        $grossVatExc = $grossVatInc / $divisor;

        // STEP 2: Compute deductions from the VAT-exclusive amount
        $vatDeduction = $grossVatExc * ($vat / 100);
        $ewtDeduction = $grossVatExc * ($ewt / 100);

        // STEP 3: Total deductions
        $deductionAmount = $vatDeduction + $ewtDeduction;

        // STEP 4: Net amount payable
        $netAmount = $grossVatInc - $deductionAmount;

        // SNAPSHOT VALUES
        $data = [
            'disbursement_id' => $validated['disbursement_id'] ?? null,

            'deduction_code_id' => $libCode->id,

            'deduction_type' => $libCode->deduction_type,
            'tax_type'       => $libCode->tax_type,
            'code'           => $libCode->code,

            'divisor'        => $libCode->divisor,
            'vat_percent'    => $libCode->vat_percent,
            'ewt_percent'    => $libCode->ewt_percent,

            'description'    => $validated['description'] ?? null,

            'gross_vat_inc'  => round($grossVatInc, 2),
            'gross_vat_exc'  => round($grossVatExc, 2),

            'deduction_amount' => round($deductionAmount, 2),
            'net_amount'       => round($netAmount, 2), // ADD THIS
        ];

        \Log::info('Deduction data:', $data);

        $deduction = Deduction::create($data);

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
            'deduction_code_id' => 'required|exists:lib_deduction_codes,id',
            'gross_vat_inc' => 'required|numeric|min:0',
        ]);

        $libCode = LibDeductionCode::findOrFail(
            $validated['deduction_code_id']
        );

        $grossVatInc = (float) $validated['gross_vat_inc'];

        $divisor = (float) ($libCode->divisor ?: 1);

        if ($divisor <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid divisor value.'
            ], 422);
        }
        $vat = (float) ($libCode->vat_percent ?? 0);
        $ewt = (float) ($libCode->ewt_percent ?? 0);

        // STEP 1: Remove VAT from the gross amount
        $grossVatExc = $grossVatInc / $divisor;

        // STEP 2: Compute deductions from the VAT-exclusive amount
        $vatDeduction = $grossVatExc * ($vat / 100);
        $ewtDeduction = $grossVatExc * ($ewt / 100);

        // STEP 3: Total deductions
        $deductionAmount = $vatDeduction + $ewtDeduction;

        // STEP 4: Net amount payable
        $netAmount = $grossVatInc - $deductionAmount;

        return response()->json([
            'status' => true,
            'data' => [
                'code' => $libCode->code,
                'deduction_type' => $libCode->deduction_type,
                'tax_type' => $libCode->tax_type,

                'gross_vat_inc' => round($grossVatInc, 2),
                'gross_vat_exc' => round($grossVatExc, 2),
                'deduction_amount' => round($deductionAmount, 2),
                'net_amount' => round($netAmount, 2), // ADD THIS
            ]
        ]);
    }

    public function update(Request $request, $id)
    {
        $deduction = Deduction::findOrFail($id);

        $validated = $request->validate([
            'deduction_code_id' => 'required|exists:lib_deduction_codes,id',
            'description' => 'nullable|string',
            'gross_vat_inc' => 'required|numeric|min:0',
        ]);

        $libCode = LibDeductionCode::findOrFail(
            $validated['deduction_code_id']
        );

        $grossVatInc = (float) $validated['gross_vat_inc'];

        $divisor = (float) ($libCode->divisor ?: 1);

        if ($divisor <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid divisor value.'
            ], 422);
        }
        $vat = (float) ($libCode->vat_percent ?? 0);
        $ewt = (float) ($libCode->ewt_percent ?? 0);

        // STEP 1: Remove VAT from the gross amount
        $grossVatExc = $grossVatInc / $divisor;

        // STEP 2: Compute deductions from the VAT-exclusive amount
        $vatDeduction = $grossVatExc * ($vat / 100);
        $ewtDeduction = $grossVatExc * ($ewt / 100);

        // STEP 3: Total deductions
        $deductionAmount = $vatDeduction + $ewtDeduction;

        // STEP 4: Net amount payable
        $netAmount = $grossVatInc - $deductionAmount;

        $deduction->update([
            'deduction_code_id' => $libCode->id,

            'deduction_type' => $libCode->deduction_type,
            'tax_type' => $libCode->tax_type,
            'code' => $libCode->code,

            'divisor' => $libCode->divisor,
            'vat_percent' => $libCode->vat_percent,
            'ewt_percent' => $libCode->ewt_percent,

            'description' => $validated['description'] ?? null,

            'gross_vat_inc' => round($grossVatInc, 2),
            'gross_vat_exc' => round($grossVatExc, 2),
            'deduction_amount' => round($deductionAmount, 2),
            'net_amount' => round($netAmount, 2), // ADD THIS
        ]);

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
