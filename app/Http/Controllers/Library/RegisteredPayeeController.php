<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RegisteredPayee;

class RegisteredPayeeController extends Controller
{
    // GET /api/library/registered-payees
    public function index()
    {
        return response()->json([
            'status' => true,
            'data' => RegisteredPayee::orderBy('payee_name')->get()
        ]);
    }

    //POST /api/library/registered-payees
    public function store(Request $request)
    {
        $validated = $request->validate([
            'barangay_id'    => 'required|exists:barangays,id',

            'firstname'      => 'nullable|string|max:255',
            'middlename'     => 'nullable|string|max:255',
            'lastname'       => 'nullable|string|max:255',

            'payee_name'     => 'required|string|max:255',
            'payee2_name'    => 'nullable|string|max:255',

            'type'           => 'required|in:Local,Foreign',
            'address'        => 'nullable|string',
            'zip_code'       => 'nullable|string|max:50',

            'taxpayer_type'  => 'nullable|string|max:100',
            'tin_number'     => 'nullable|string|max:50',

            'description'    => 'nullable|string',

            'status'         => 'nullable|in:Active,Inactive',
        ]);

        $validated['status'] = $validated['status'] ?? 'Active';
        $payee = RegisteredPayee::create($validated);

        return response()->json([
            'status'  => true,
            'message' => 'Registered payee created successfully',
            'data'    => $payee
        ], 201);
    }

    //GET /api/library/registered-payees/{id}
    public function show($id)
    {
        return response()->json([
            'status' => true,
            'data'   => RegisteredPayee::findOrFail($id)
        ]);
    }

    //PUT /api/library/registered-payees/{id}
    public function update(Request $request, $id)
    {
        $payee = RegisteredPayee::findOrFail($id);

        $validated = $request->validate([
            'firstname'      => 'nullable|string|max:255',
            'middlename'     => 'nullable|string|max:255',
            'lastname'       => 'nullable|string|max:255',

            'payee_name'     => 'required|string|max:255',
            'payee2_name'    => 'nullable|string|max:255',

            'type'           => 'required|in:Local,Foreign',
            'address'        => 'nullable|string',
            'zip_code'       => 'nullable|string|max:50',

            'taxpayer_type'  => 'nullable|string|max:100',
            'tin_number'     => 'nullable|string|max:50',

            'description'    => 'nullable|string',

            'status'         => 'nullable|in:Active,Inactive',
        ]);

        $payee->update($validated);

        return response()->json([
            'status'  => true,
            'message' => 'Registered payee updated successfully',
            'data' => $payee
        ]);
    }

    //DELETE /api/library/registered-payees/{id}
    public function destroy($id)
    {
        $payee = RegisteredPayee::findOrFail($id);

        $payee->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Registered payee deleted successfully'
        ]);
    }

    //Search payees for disbursement dropdown/autocomplete
    //GET /api/library/registered-payees/search?q=juan
    public function search(Request $request)
    {
        $q = $request->q;

        $payees = RegisteredPayee::query()
            ->when($q, function ($query) use ($q) {
                $query->where('payee_name', 'like', "%{$q}%")
                    ->orWhere('payee2_name', 'like', "%{$q}%")
                    ->orWhere('firstname', 'like', "%{$q}%")
                    ->orWhere('lastname', 'like', "%{$q}%");
            })
            ->limit(20)
            ->get();

        //this query to show only the active payees for disbursement
        // $payees = RegisteredPayee::query()
        //         ->where('status', 'Active')
        //         ->when($q, function ($query) use ($q) {
        //             $query->where('payee_name', 'like', "%{$q}%")
        //                 ->orWhere('payee2_name', 'like', "%{$q}%")
        //                 ->orWhere('firstname', 'like', "%{$q}%")
        //                 ->orWhere('lastname', 'like', "%{$q}%");
        //         })
        //         ->limit(20)
        //         ->get();

        return response()->json([
            'status' => true,
            'data' => $payees
        ]);
    }
}
