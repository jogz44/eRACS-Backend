<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\LibParticular;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LibParticularController extends Controller
{
    // Get all particulars for current barangay
    public function index()
    {
        return LibParticular::where('barangay_id', Auth::user()->barangay_id)
            ->orderBy('id', 'desc')
            ->get();
    }

    // Create new particular
     public function store(Request $request)
    {
        $validated = $request->validate([
            'particular' => 'required|string|max:255'
        ]);

        $particular = LibParticular::create([
            'barangay_id' => Auth::user()->barangay_id,
            'particular_name' => $validated['particular'],
            'created_by' => Auth::id()
        ]);

        return response()->json([
            'id' => $particular->id,
            'particular' => $particular->particular_name,
            'date' => $particular->created_at->format('d/m/Y')
        ], 201);
    }
    public function show($id)
    {
        $particular = LibParticular::where('barangay_id', Auth::user()->barangay_id)
            ->findOrFail($id);

        return response()->json([
            'id' => $particular->id,
            'particular' => $particular->particular_name,
            'date' => $particular->created_at->format('d/m/Y')
        ]);
    }

    // Update particular
    public function update(Request $request, $id)
    {
        $particular = LibParticular::where('barangay_id', Auth::user()->barangay_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'particular' => 'required|string|max:255'
        ]);

        $particular->update([
            'particular_name' => $validated['particular']
        ]);

        return response()->json([
            'id' => $particular->id,
            'particular' => $particular->particular_name,
            'date' => $particular->created_at->format('d/m/Y')
        ]);
    }

    // Delete particular
     public function destroy($id)
    {
        $particular = LibParticular::where('barangay_id', Auth::user()->barangay_id)
            ->findOrFail($id);

        $particular->delete();

        return response()->json(['message' => 'Particular deleted successfully']);
    }
}
