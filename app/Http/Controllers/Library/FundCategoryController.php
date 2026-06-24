<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\FundCategory;
use Illuminate\Http\Request;

class FundCategoryController extends Controller
{
    // Fetch all
    public function index()
    {
        return response()->json([
            'status' => true,
            'data' => FundCategory::all()
        ]);
    }

    // Create
    public function store(Request $request)
    {
        $validated = $request->validate([
            'F_Code' => 'required|unique:fund_categories,F_Code',
            'F_Description' => 'required'
        ]);

        $fund = FundCategory::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Created successfully',
            'data' => $fund
        ]);
    }

    // View single
    public function show($id)
    {
        return response()->json([
            'status' => true,
            'data' => FundCategory::findOrFail($id)
        ]);
    }

    // Update
    public function update(Request $request, $id)
    {
        $fund = FundCategory::findOrFail($id);

        $validated = $request->validate([
            'F_Code' => "required|unique:fund_categories,F_Code,$id,F_ID",
            'F_Description' => 'required'
        ]);

        $fund->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Updated successfully',
            'data' => $fund
        ]);
    }

    // Delete
    public function destroy($id)
    {
        FundCategory::destroy($id);

        return response()->json([
            'status' => true,
            'message' => 'Deleted successfully'
        ]);
    }

}
