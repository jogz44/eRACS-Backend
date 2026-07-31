<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\BarangaySetup;
use App\Models\BarangayUser;
use App\Models\BarangayBankAccount;


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
            'bankAccounts.bank',
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
            'registered_user_id'             => 'required|exists:barangay_users,id',
            'barangay_position_id'           => 'required|exists:barangay_positions,id',

            'bank_accounts'                  => 'required|array|min:1',
            'bank_accounts.*.id'             => 'nullable|integer',
            'bank_accounts.*.bank_id'        => 'required|exists:lib_banks,id',
            'bank_accounts.*.account_number' => 'required|string|max:100',
            'bank_accounts.*.bank_status' => 'required|in:online,offline',
            'bank_accounts.*.is_default' => 'nullable|boolean',

            'noted_by'                       => 'required|string|max:255',
            'noted_by_position_id'           => 'required|exists:barangay_positions,id',
            'certified_by'                   => 'required|string|max:255',
            'certified_by_position_id'       => 'required|exists:barangay_positions,id',
        ]);

        // Ensure exactly one default account
        $defaultCount = collect($request->bank_accounts)
            ->where('is_default', true)
            ->count();

        if ($defaultCount !== 1) {
            return response()->json([
                'status' => false,
                'message' => 'Exactly one bank account must be marked as default.'
            ], 422);
        }

        // Prevent duplicate bank/account combinations
        $duplicates = collect($request->bank_accounts)
            ->map(fn ($item) => $item['bank_id'].'-'.$item['account_number']);

        if ($duplicates->count() !== $duplicates->unique()->count()) {
            return response()->json([
                'status' => false,
                'message' => 'Duplicate bank account entries are not allowed.'
            ], 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($request, $user, &$setup) {

            $setup = BarangaySetup::updateOrCreate(
                [
                    'barangay_id' => $user->barangay_id,
                ],
                [
                    'registered_user_id'       => $request->registered_user_id,
                    'barangay_position_id'     => $request->barangay_position_id,
                    'noted_by'                 => $request->noted_by,
                    'noted_by_position_id'     => $request->noted_by_position_id,
                    'certified_by'             => $request->certified_by,
                    'certified_by_position_id' => $request->certified_by_position_id,
                ]
            );

            // Remove existing bank accounts
            $setup->bankAccounts()->delete();

            // Save all bank accounts
            foreach ($request->bank_accounts as $bank) {

                $setup->bankAccounts()->create([
                    'bank_id'        => $bank['bank_id'],
                    'account_number' => $bank['account_number'],
                    'bank_status'    => $bank['bank_status'],
                    'is_default'     => $bank['is_default'] ?? false,
                ]);
            }

        });

        return response()->json([
            'status' => true,
            'message' => 'Barangay setup saved successfully.',
            'data' => $this->transform(
                $setup->load([
                    'barangay',
                    'user',
                    'position',
                    'bankAccounts.bank',
                    'notedByPosition',
                    'certifiedByPosition',
                ])
            ),
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
            // 'bank',
            'bankAccounts.bank',
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
        $setup = BarangaySetup::with('bankAccounts')->findOrFail($id);

        $request->validate([
            'registered_user_id'             => 'required|exists:barangay_users,id',
            'barangay_position_id'           => 'required|exists:barangay_positions,id',

            'bank_accounts'                  => 'required|array|min:1',
            'bank_accounts.*.id'             => 'nullable|exists:barangay_bank_accounts,id',
            'bank_accounts.*.bank_id'        => 'required|exists:lib_banks,id',
            'bank_accounts.*.account_number' => 'required|string|max:100',
            'bank_accounts.*.bank_status' => 'required|in:online,offline',
            'bank_accounts.*.is_default' => 'nullable|boolean',

            'noted_by'                       => 'required|string|max:255',
            'noted_by_position_id'           => 'required|exists:barangay_positions,id',
            'certified_by'                   => 'required|string|max:255',
            'certified_by_position_id'       => 'required|exists:barangay_positions,id',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate exactly one default account
        |--------------------------------------------------------------------------
        */

        $defaultCount = collect($request->bank_accounts)
            ->filter(fn($account) => !empty($account['is_default']))
            ->count();

        if ($defaultCount !== 1) {
            return response()->json([
                'status' => false,
                'message' => 'Exactly one bank account must be marked as default.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate bank + account numbers
        |--------------------------------------------------------------------------
        */

        $duplicates = collect($request->bank_accounts)
            ->map(fn($item) => trim($item['bank_id']) . '-' . trim($item['account_number']));

        if ($duplicates->count() !== $duplicates->unique()->count()) {
            return response()->json([
                'status' => false,
                'message' => 'Duplicate bank account entries are not allowed.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Update Barangay Setup
        |--------------------------------------------------------------------------
        */

        $setup->update([
            'registered_user_id'       => $request->registered_user_id,
            'barangay_position_id'     => $request->barangay_position_id,
            'noted_by'                 => $request->noted_by,
            'noted_by_position_id'     => $request->noted_by_position_id,
            'certified_by'             => $request->certified_by,
            'certified_by_position_id' => $request->certified_by_position_id,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Sync Bank Accounts
        |--------------------------------------------------------------------------
        */

        $existingIds = $setup->bankAccounts()->pluck('id')->toArray();
        $submittedIds = [];

        foreach ($request->bank_accounts as $account) {

            if (!empty($account['id'])) {

                $bankAccount = BarangayBankAccount::where('barangay_setup_id', $setup->id)
                    ->where('id', $account['id'])
                    ->first();

                if ($bankAccount) {

                    $bankAccount->update([
                        'bank_id'        => $account['bank_id'],
                        'account_number' => $account['account_number'],
                        'bank_status'    => $account['bank_status'],
                        'is_default'     => !empty($account['is_default']),
                    ]);

                    $submittedIds[] = $bankAccount->id;
                }

            } else {

                $newAccount = $setup->bankAccounts()->create([
                    'bank_id'        => $account['bank_id'],
                    'account_number' => $account['account_number'],
                    'bank_status'    => $account['bank_status'],
                    'is_default'     => !empty($account['is_default']),
                ]);

                $submittedIds[] = $newAccount->id;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Delete removed accounts
        |--------------------------------------------------------------------------
        */

        $idsToDelete = array_diff($existingIds, $submittedIds);

        if (!empty($idsToDelete)) {
            BarangayBankAccount::whereIn('id', $idsToDelete)->delete();
        }

        /*
        |--------------------------------------------------------------------------
        | Return updated data
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'status' => true,
            'message' => 'Barangay setup updated successfully.',
            'data' => $this->transform(
                $setup->fresh()->load([
                    'barangay',
                    'user',
                    'position',
                    'bankAccounts.bank',
                    'notedByPosition',
                    'certifiedByPosition',
                ])
            )
        ]);
    }

    /**
     * Transform response.
     */
    private function transform(BarangaySetup $setup)
    {
        return [
            'id' => $setup->id,

            'barangay_id'             => $setup->barangay_id,
            'barangay'                => optional($setup->barangay)->name,

            'registered_user_id'      => $setup->registered_user_id,

            'prepared_by'              => $setup->prepared_by,

            'barangay_position_id'     => $setup->barangay_position_id,
            'barangay_position'        => $setup->barangay_position,

            'noted_by'                 => $setup->noted_by,
            'noted_by_position_id'     => $setup->noted_by_position_id,
            'noted_by_position'        => $setup->noted_by_position_name,

            'certified_by'             => $setup->certified_by,
            'certified_by_position_id' => $setup->certified_by_position_id,
            'certified_by_position'    => $setup->certified_by_position_name,

            // 'bank_id'                  => $setup->bank_id,
            // 'bank'                     => optional($setup->bank)->bank_name,
            // 'account_number'           => $setup->account_number,

            'bank_accounts' => $setup->bankAccounts->map(function ($account) {
                return [
                    'id' => $account->id,
                    'bank_id' => $account->bank_id,
                    'bank' => optional($account->bank)->bank_name,
                    'account_number' => $account->account_number,
                    'bank_status' => $account->bank_status,
                    'is_default' => (bool) $account->is_default,
                ];

            })->values(),

            'created_at'               => $setup->created_at,
            'updated_at'               => $setup->updated_at,
        ];
    }
}
