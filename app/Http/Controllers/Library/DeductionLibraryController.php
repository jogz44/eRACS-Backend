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
}
