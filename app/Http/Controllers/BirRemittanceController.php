<?php

namespace App\Http\Controllers;

use App\Models\BirRemittance;
use App\Models\BirBankCheque;
use App\Models\LibBooklet;
use App\Models\LibCheque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AdminAuthController;
use App\Models\BarangaySetup;

class BirRemittanceController extends Controller
{
    // GET /api/barangay/bir-remittances
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $query = BirRemittance::with([
            'bankCheques.bank',
            'barangay'
        ]);

        // Admin can choose barangay
        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        } else {
            // Barangay users
            $query->where('barangay_id', $user->barangay_id);
        }

        $year = $request->input('year', now()->year);

        $query->whereYear('date', $year);

        $items = $query
            ->orderByDesc('date')
            ->get()
            ->map(fn ($d) => $this->format($d));

        return response()->json([
            'status' => true,
            'data' => $items,
        ]);
    }

    // POST /api/barangay/bir-remittances
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date'          => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',

            'dv_number' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('bir_remittances', 'dv_number'),
            ],

            'dv_amount' => 'required|numeric|min:0.01',

            'bank_cheques' => 'required|array|min:1',
            'bank_cheques.*.bank_id' => 'required|exists:lib_banks,id',
            'bank_cheques.*.cheque_number' => 'required|string',
            'bank_cheques.*.cheque_date' => 'nullable|date',

            'bank_cheques.*.amount' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {

            $user = $request->user();

            $setup = BarangaySetup::with('bankAccounts')
                ->where('barangay_id', $user->barangay_id)
                ->first();

            if (!$setup) {
                return response()->json([
                    'status' => false,
                    'message' => 'Barangay setup not found.'
                ], 422);
            }

            [$dd, $mm, $yyyy] = explode('/', $validated['date']);
            $formattedDate = "$yyyy-$mm-$dd";

            $firstCheque = $validated['bank_cheques'][0];

            $defaultBankAccount = $setup->bankAccounts
                ->where('bank_id', $firstCheque['bank_id'])
                ->first();

            if (!$defaultBankAccount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Selected bank has not been configured in Barangay Setup.'
                ], 422);
            }

            // Create parent remittance
            $record = BirRemittance::create([
                'barangay_id' => $user->barangay_id,
                'date' => $formattedDate,
                'dv_number' => $validated['dv_number'],

                // Required by current table
                'bank_id' => $firstCheque['bank_id'],
                'cheque_number' => $firstCheque['cheque_number'],
                'cheque_date' => $firstCheque['cheque_date'] ?? null,
                'bank_status' => $defaultBankAccount->bank_status,

                'payee' => 'Bureau of Internal Revenue',
                'dv_amount' => $validated['dv_amount'],
                'status' => 'Unliquidated',
                'user_id' => $user->id,
            ]);

            // Save ALL bank cheques
            foreach ($validated['bank_cheques'] as $row) {

                $bankAccount = $setup->bankAccounts
                    ->where('bank_id', $row['bank_id'])
                    ->first();

                if (!$bankAccount) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Selected bank has not been configured in Barangay Setup.'
                    ], 422);
                }

                BirBankCheque::create([
                    'bir_remittance_id' => $record->id,
                    'bank_id'           => $row['bank_id'],
                    'cheque_number'     => $row['cheque_number'],
                    'cheque_date'       => $row['cheque_date'] ?? null,
                    'bank_status'       => $bankAccount->bank_status,
                    'amount'            => $row['amount'],
                ]);

                // Find the booklet for this bank
                $booklets = LibBooklet::where(
                    'bank_id',
                    $row['bank_id']
                )->pluck('id');

                // Mark this cheque as used
                $libCheque = LibCheque::where(
                    'cheque_number',
                    $row['cheque_number']
                )
                ->whereIn('booklet_id', $booklets)
                ->where('status', 'unused')
                ->first();

                if ($libCheque) {

                    $libCheque->update([
                        'status' => 'used',
                        'disbursement_id' => $record->id,
                    ]);

                }
            }

            // Update booklet/bank statuses
            (new \App\Http\Controllers\Library\BankLibraryController())
                ->updateBanksStatus($user->barangay_id);

            DB::commit();

            AdminAuthController::logUserAction(
                $user,
                'Created BIR Remittance',
                sprintf(
                    '#%s amount ₱%s (%d cheque(s))',
                    $record->dv_number,
                    number_format($record->dv_amount, 2),
                    count($validated['bank_cheques'])
                )
            );

            return response()->json([
                'status' => true,
                'message' => 'BIR Remittance created successfully.',
                'data' => $record->load('bankCheques.bank'),
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);

        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date'          => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',

            'dv_number' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('bir_remittances', 'dv_number')->ignore($id),
            ],

            'dv_amount' => 'required|numeric|min:0.01',

            'bank_cheques' => 'required|array|min:1',
            'bank_cheques.*.bank_id' => 'required|exists:lib_banks,id',
            'bank_cheques.*.cheque_number' => 'required|string',
            'bank_cheques.*.cheque_date' => 'nullable|date',
            'bank_cheques.*.amount' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {

            $user = $request->user();

            $record = BirRemittance::with('bankCheques')
                ->where('barangay_id', $user->barangay_id)
                ->findOrFail($id);

            $setup = BarangaySetup::with('bankAccounts')
                ->where('barangay_id', $user->barangay_id)
                ->first();

            if (!$setup) {
                return response()->json([
                    'status' => false,
                    'message' => 'Barangay setup not found.'
                ], 422);
            }

            [$dd, $mm, $yyyy] = explode('/', $validated['date']);
            $formattedDate = "$yyyy-$mm-$dd";

            /*
            |--------------------------------------------------------------------------
            | RESTORE OLD CHEQUES
            |--------------------------------------------------------------------------
            */

            foreach ($record->bankCheques as $oldCheque) {

                $booklets = LibBooklet::where('bank_id', $oldCheque->bank_id)
                    ->pluck('id');

                $libCheque = LibCheque::where('cheque_number', $oldCheque->cheque_number)
                    ->whereIn('booklet_id', $booklets)
                    ->where('status', 'used')
                    ->first();

                if ($libCheque) {
                    $libCheque->update([
                        'status' => 'unused',
                        'disbursement_id' => null,
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | DELETE OLD BANK CHEQUES
            |--------------------------------------------------------------------------
            */

            BirBankCheque::where('bir_remittance_id', $record->id)->delete();

            /*
            |--------------------------------------------------------------------------
            | UPDATE HEADER
            |--------------------------------------------------------------------------
            */

            $firstCheque = $validated['bank_cheques'][0];

            $defaultBankAccount = $setup->bankAccounts
                ->where('bank_id', $firstCheque['bank_id'])
                ->first();

            $record->update([
                'date'           => $formattedDate,
                'dv_number'      => $validated['dv_number'],
                'dv_amount'      => $validated['dv_amount'],
                'bank_id'        => $firstCheque['bank_id'],
                'cheque_number'  => $firstCheque['cheque_number'],
                'cheque_date'    => $firstCheque['cheque_date'] ?? null,
                'bank_status'    => $defaultBankAccount->bank_status,
            ]);

            /*
            |--------------------------------------------------------------------------
            | INSERT NEW BANK CHEQUES
            |--------------------------------------------------------------------------
            */

            foreach ($validated['bank_cheques'] as $row) {

                $bankAccount = $setup->bankAccounts
                    ->where('bank_id', $row['bank_id'])
                    ->first();

                BirBankCheque::create([
                    'bir_remittance_id' => $record->id,
                    'bank_id'           => $row['bank_id'],
                    'cheque_number'     => $row['cheque_number'],
                    'cheque_date'       => $row['cheque_date'] ?? null,
                    'bank_status'       => $bankAccount->bank_status,
                    'amount'            => $row['amount'],
                ]);

                $booklets = LibBooklet::where('bank_id', $row['bank_id'])
                    ->pluck('id');

                $libCheque = LibCheque::where('cheque_number', $row['cheque_number'])
                    ->whereIn('booklet_id', $booklets)
                    ->where('status', 'unused')
                    ->first();

                if ($libCheque) {

                    $libCheque->update([
                        'status' => 'used',
                        'disbursement_id' => $record->id,
                    ]);

                }
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE BANK STATUS
            |--------------------------------------------------------------------------
            */

            (new \App\Http\Controllers\Library\BankLibraryController())
                ->updateBanksStatus($user->barangay_id);

            DB::commit();

            AdminAuthController::logUserAction(
                $user,
                'Updated BIR Remittance',
                sprintf(
                    '#%s amount ₱%s (%d cheque(s))',
                    $record->dv_number,
                    number_format($record->dv_amount, 2),
                    count($validated['bank_cheques'])
                )
            );

            return response()->json([
                'status' => true,
                'message' => 'BIR Remittance updated successfully.',
                'data' => $record->fresh()->load('bankCheques.bank'),
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // GET /api/barangay/bir-remittances/{id}
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $record = BirRemittance::with(['bankCheques.bank', 'barangay'])
            ->where('barangay_id', $user->barangay_id)
            ->findOrFail($id);

        return response()->json(['status' => true, 'data' => $this->format($record)]);
    }

    // POST /api/barangay/bir-remittances/{id}/void-request
    public function requestVoid(Request $request, $id)
    {
        $request->validate(['remarks' => 'required|string|max:500']);
        $user = $request->user();

        $record = BirRemittance::where('barangay_id', $user->barangay_id)->findOrFail($id);

        if (!in_array($record->status, ['Unliquidated', 'Partial'])) {
            return response()->json(['status' => false, 'message' => 'Cannot void this record'], 400);
        }

        $record->update(['status' => 'Void Requested', 'remarks' => $request->remarks]);

        AdminAuthController::logUserAction($user, 'BIR Void Requested',
            sprintf('#%s requested void', $record->dv_number));

        return response()->json(['status' => true, 'message' => 'Void request submitted']);
    }

    // POST /api/barangay/bir-remittances/{id}/void-direct
    public function voidDirect(Request $request, $id)
    {
        $request->validate([
            'remarks' => 'required|string|max:500'
        ]);

        $user = $request->user();

        $position = strtolower(optional($user->position)->name ?? '');
        $canVoid = str_contains($position, 'captain') || str_contains($position, 'chairperson');

        if (!$canVoid) {
            return response()->json([
                'status' => false,
                'message' => 'Only Captain/Chairperson can void directly'
            ], 403);
        }

        $record = BirRemittance::with('bankCheques')
            ->where('barangay_id', $user->barangay_id)
            ->findOrFail($id);

        if (!in_array($record->status, ['Unliquidated', 'Partial'])) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot void this record'
            ], 400);
        }

        DB::beginTransaction();

        try {

            $record->update([
                'status' => 'Voided',
                'remarks' => $request->remarks
            ]);

            foreach ($record->bankCheques as $bankCheque) {

                $booklets = LibBooklet::where('bank_id', $bankCheque->bank_id)
                    ->pluck('id');

                $libCheque = LibCheque::where('cheque_number', $bankCheque->cheque_number)
                    ->whereIn('booklet_id', $booklets)
                    ->where('status', 'used')
                    ->first();

                if ($libCheque) {
                    $libCheque->update([
                        'status' => 'void',
                        'disbursement_id' => null,
                    ]);
                }
            }

            (new \App\Http\Controllers\Library\BankLibraryController())
                ->updateBanksStatus($user->barangay_id);

            AdminAuthController::logUserAction(
                $user,
                'BIR Voided Directly',
                sprintf('#%s voided', $record->dv_number)
            );

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Voided successfully'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            \Log::error('Error voiding BIR Remittance: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to void BIR Remittance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/bir-remittances/{id}/void-approve
    public function approveVoid(Request $request, $id)
    {
        $user = $request->user();

        $position = strtolower(optional($user->position)->name ?? '');
        $canApprove = str_contains($position, 'captain') || str_contains($position, 'chairperson');

        if (!$canApprove) {
            return response()->json([
                'status' => false,
                'message' => 'Only Captain/Chairperson can approve'
            ], 403);
        }

        $record = BirRemittance::with('bankCheques')
            ->where('barangay_id', $user->barangay_id)
            ->findOrFail($id);

        if ($record->status !== 'Void Requested') {
            return response()->json([
                'status' => false,
                'message' => 'Status must be Void Requested'
            ], 400);
        }

        DB::beginTransaction();

        try {

            $record->update([
                'status' => 'Voided'
            ]);

            foreach ($record->bankCheques as $bankCheque) {

                $booklets = LibBooklet::where('bank_id', $bankCheque->bank_id)
                    ->pluck('id');

                $libCheque = LibCheque::where('cheque_number', $bankCheque->cheque_number)
                    ->whereIn('booklet_id', $booklets)
                    ->where('status', 'used')
                    ->first();

                if ($libCheque) {
                    $libCheque->update([
                        'status' => 'void',
                        'disbursement_id' => null,
                    ]);
                }
            }

            (new \App\Http\Controllers\Library\BankLibraryController())
                ->updateBanksStatus($user->barangay_id);

            AdminAuthController::logUserAction(
                $user,
                'BIR Void Approved',
                sprintf('#%s void approved', $record->dv_number)
            );

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Void approved'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            \Log::error('Error approving BIR Remittance void: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to approve void request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/barangay/bir-remittances/{id}/void-reject
    public function rejectVoid(Request $request, $id)
    {
        $request->validate(['remarks' => 'required|string|max:500']);
        $user = $request->user();
        $position = strtolower(optional($user->position)->name ?? '');
        $canReject = str_contains($position, 'captain') || str_contains($position, 'chairperson');

        if (!$canReject) {
            return response()->json(['status' => false, 'message' => 'Only Captain/Chairperson can reject'], 403);
        }

        $record = BirRemittance::where('barangay_id', $user->barangay_id)->findOrFail($id);

        if ($record->status !== 'Void Requested') {
            return response()->json(['status' => false, 'message' => 'Status must be Void Requested'], 400);
        }

        $record->update(['status' => 'Unliquidated', 'rejection_remarks' => $request->remarks]);

        AdminAuthController::logUserAction($user, 'BIR Void Rejected',
            sprintf('#%s void rejected', $record->dv_number));

        return response()->json(['status' => true, 'message' => 'Void rejected']);
    }

    public function pendingTaxTotal(Request $request)
    {
        try {
            $user = $request->user();
            $barangayId = $user?->barangay_id;

            // Sum of BIR remittances that are unliquidated/pending
            // Adjust the model/table name to match your actual setup
            $total = \App\Models\BirRemittance::when($barangayId, function ($q) use ($barangayId) {
                    $q->where('barangay_id', $barangayId);
                })
                ->where('status', 'Unliquidated')
                ->sum('dv_amount');

            return response()->json([
                'status' => true,
                'pending_total' => (float) $total,
            ]);
        } catch (\Exception $e) {
            \Log::error('pendingTaxTotal error: ' . $e->getMessage());
            return response()->json([
                'status' => true,
                'pending_total' => 0,
            ]);
        }
    }

    private function format(BirRemittance $d): array
    {
        return [
            'id'                => $d->id,
            'date'              => $d->date,
            'dv_number'         => $d->dv_number,
            'bank_cheques'=>$d->bankCheques->map(function($c){
                return [
                    'id'=>$c->id,
                    'bank_id'=>$c->bank_id,
                    'bank_name'=>optional($c->bank)->bank_name,
                    'cheque_number'=>$c->cheque_number,
                    'cheque_date'=>$c->cheque_date,
                    'bank_status'=>$c->bank_status,
                    'amount'=>$c->amount
                ];
            }),
            'payee'             => $d->payee,
            'dv_amount'         => $d->dv_amount,
            'status'            => $d->status,
            'remarks'           => $d->remarks,
            'rejection_remarks' => $d->rejection_remarks,
            'type'              => 'bir',
            'created_at'        => $d->created_at,
            'updated_at'        => $d->updated_at,
        ];
    }
}
