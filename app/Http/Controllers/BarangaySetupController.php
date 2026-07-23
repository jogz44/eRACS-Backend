<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BarangaySetup;
use App\Models\BarangayUser;

class BarangaySetupController extends Controller
{
    /**
     * Display all barangay setups.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $setup = BarangaySetup::with([
            'barangay',
            'user',
            'position',
            'bank',
            'notedByPosition',
            'certifiedByPosition',
        ])
        ->where('barangay_id', $user->barangay_id)
        ->first();

        return response()->json([
            'status' => true,
            'data' => $setup ? $this->transform($setup) : null,
        ]);
    }

    /**
     * Store new barangay setup.
     */
    public function store(Request $request)
    {
        $request->validate([
            'registered_user_id' => 'required|exists:barangay_users,id',
            'barangay_position_id' => 'required|exists:barangay_positions,id',
            'bank_id' => 'required|exists:lib_banks,id',
            'account_number' => 'required|string|max:100',
            'noted_by' => 'required|string|max:255',
            'noted_by_position_id' => 'required|exists:barangay_positions,id',
            'certified_by' => 'required|string|max:255',
            'certified_by_position_id' => 'required|exists:barangay_positions,id',
        ]);

        // Prevent duplicate setup per barangay
        $user = $request->user();

        $setup = BarangaySetup::updateOrCreate(
            [
                'barangay_id' => $user->barangay_id,
            ],
            [
                'registered_user_id' => $request->registered_user_id,
                'barangay_position_id' => $request->barangay_position_id,
                'bank_id' => $request->bank_id,
                'account_number' => $request->account_number,
                'noted_by' => $request->noted_by,
                'noted_by_position_id' => $request->noted_by_position_id,
                'certified_by' => $request->certified_by,
                'certified_by_position_id' => $request->certified_by_position_id,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'Barangay setup saved successfully.',
            'data' => $this->transform($setup->load([
                'barangay',
                'user',
                'position',
                'bank',
                'notedByPosition',
                'certifiedByPosition',
            ]))
        ]);
    }

    /**
     * Display one barangay setup.
     */
    public function show($id)
    {
        $setup = BarangaySetup::with([
            'barangay',
            'user',
            'position',
            'bank',
            'notedByPosition',
            'certifiedByPosition',
        ])->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => $this->transform($setup),
        ]);
    }

    /**
     * Update barangay setup.
     */
    public function update(Request $request, $id)
    {
        $setup = BarangaySetup::findOrFail($id);

        $request->validate([
            'registered_user_id' => 'required|exists:barangay_users,id',
            'barangay_position_id' => 'required|exists:barangay_positions,id',
            'bank_id' => 'required|exists:lib_banks,id',
            'account_number' => 'required|string|max:100',
            'noted_by' => 'required|string|max:255',
            'noted_by_position_id' => 'required|exists:barangay_positions,id',
            'certified_by' => 'required|string|max:255',
            'certified_by_position_id' => 'required|exists:barangay_positions,id',
        ]);

        $setup->update([
            'registered_user_id' => $request->registered_user_id,
            'barangay_position_id' => $request->barangay_position_id,
            'bank_id' => $request->bank_id,
            'account_number' => $request->account_number,
            'noted_by' => $request->noted_by,
            'noted_by_position_id' => $request->noted_by_position_id,
            'certified_by' => $request->certified_by,
            'certified_by_position_id' => $request->certified_by_position_id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Barangay setup updated successfully.',
            'data' => $this->transform($setup->load([
                'barangay',
                'user',
                'position',
                'bank',
                'notedByPosition',
                'certifiedByPosition',
            ]))
        ]);
    }

    /**
     * Transform response.
     */
    private function transform(BarangaySetup $setup)
    {
        return [
            'id' => $setup->id,

            'barangay_id' => $setup->barangay_id,
            'barangay' => optional($setup->barangay)->name,

            'registered_user_id' => $setup->registered_user_id,

            'prepared_by' => $setup->prepared_by,

            'barangay_position_id' => $setup->barangay_position_id,
            'barangay_position' => $setup->barangay_position,

            'noted_by' => $setup->noted_by,
            'noted_by_position_id' => $setup->noted_by_position_id,
            'noted_by_position' => $setup->noted_by_position_name,

            'certified_by' => $setup->certified_by,
            'certified_by_position_id' => $setup->certified_by_position_id,
            'certified_by_position' => $setup->certified_by_position_name,

            'bank_id' => $setup->bank_id,
            'bank' => optional($setup->bank)->bank_name,

            'account_number' => $setup->account_number,

            'created_at' => $setup->created_at,
            'updated_at' => $setup->updated_at,
        ];
    }
}
