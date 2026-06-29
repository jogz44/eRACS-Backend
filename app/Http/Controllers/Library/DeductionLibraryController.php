<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LibDeductionCode;

class DeductionLibraryController extends Controller
{
    /**
     * Get all deduction codes
     */
    public function index(Request $request)
    {
        $query = LibDeductionCode::query();

        // Optional filtering
        if ($request->deduction_type) {
            $query->where(
                'deduction_type',
                $request->deduction_type
            );
        }

        if ($request->tax_type) {
            $query->where(
                'tax_type',
                $request->tax_type
            );
        }

        return response()->json([
            'status' => true,
            'message' => 'Request successful',
            'data' => $query
                ->orderBy('code')
                ->get()
        ]);
    }

    /**
     * Get one deduction code
     */
    public function show($code)
    {
        $item = LibDeductionCode::where(
            'code',
            $code
        )->firstOrFail();

        return response()->json([
            'status' => true,
            'data' => $item
        ]);
    }

    public function showById($id)
    {
        return response()->json([
            'status' => true,
            'data' => LibDeductionCode::findOrFail($id)
        ]);
    }

    /**
     * Get all available tax types
     */
    public function taxTypes()
    {
        $data = LibDeductionCode::select('tax_type')
            ->whereNotNull('tax_type')
            ->distinct()
            ->pluck('tax_type');

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    /**
     * Get deduction types by tax type
     */
    public function deductionTypes(Request $request)
    {
        $request->validate([
            'tax_type' => 'required|string'
        ]);

        $data = LibDeductionCode::where(
                'tax_type',
                $request->tax_type
            )
            ->select('deduction_type')
            ->distinct()
            ->pluck('deduction_type');

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    /**
     * Get codes by tax type + deduction type
     */
    public function codes(Request $request)
    {
        $request->validate([
            'tax_type' => 'required|string',
            'deduction_type' => 'required|string',
        ]);

        $data = LibDeductionCode::where(
                'tax_type',
                $request->tax_type
            )
            ->where(
                'deduction_type',
                $request->deduction_type
            )
            ->orderBy('code')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
}
