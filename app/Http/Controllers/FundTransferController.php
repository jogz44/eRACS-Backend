<?php

namespace App\Http\Controllers;

use App\Models\FundTransfer;
use App\Models\FundTransferBankCheque;
use App\Models\LibBooklet;
use App\Models\LibCheque;
use App\Models\LibFiscalYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AdminAuthController;

class FundTransferController extends Controller
{
    // GET /api/barangay/fund-transfers
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $query = FundTransfer::with([
            'bankCheques.bank',
            'barangay'
        ])->where('barangay_id', $user->barangay_id);

        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        } else {
            $query->whereYear('date', now()->year);
        }

        $items = $query
            ->latest('date')
            ->get()
            ->map(fn ($d) => $this->format($d));

        return response()->json([
            'status' => true,
            'count' => $items->count(),
            'data' => $items
        ]);
    }

    // GET /api/admin/fund-transfers
    public function adminIndex(Request $request)
    {
        $query = FundTransfer::with([
            'bankCheques.bank',
            'barangay'
        ]);

        // Optional barangay filter
        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        }

        // Optional year filter
        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        } else {
            $query->whereYear('date', now()->year);
        }

        $items = $query
            ->latest('date')
            ->get()
            ->map(fn ($d) => $this->format($d));

        return response()->json([
            'status' => true,
            'count' => $items->count(),
            'data' => $items
        ]);
    }

    // POST /api/barangay/fund-transfers
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'          => 'required|in:sk,provincial_aid',
            'date'          => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',

            'dv_number' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('disbursements', 'dv_number'),
            ],

            'bank_cheques' => 'required|array|min:1',
            'bank_cheques.*.bank_id' => 'required|exists:lib_banks,id',
            'bank_cheques.*.cheque_number' => 'required|string',
            'bank_cheques.*.cheque_date' => 'nullable|date',
            'bank_cheques.*.bank_status' => 'required|in:online,offline',
            'bank_cheques.*.amount' => 'required|numeric|min:0',

            'payee'   => 'required|string|max:255',
            'amount'  => 'required|numeric|min:0.01',
            'remarks' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();

        try {

            $user = $request->user();

            [$dd, $mm, $yyyy] = explode('/', $validated['date']);
            $formattedDate = "$yyyy-$mm-$dd";

            $firstCheque = $validated['bank_cheques'][0];

            $fiscalYear = LibFiscalYear::where('year', $yyyy)->first();

            if (!$fiscalYear) {
                return response()->json([
                    'status' => false,
                    'message' => "No fiscal year found for $yyyy"
                ], 422);
            }

            // Parent record
            $record = FundTransfer::create([

                'barangay_id'    => $user->barangay_id,
                'type'           => $validated['type'],
                'fiscal_year_id' => $fiscalYear->id,
                'date'           => $formattedDate,
                'dv_number'      => $validated['dv_number'],

                // Required by existing table
                'bank_id'        => $firstCheque['bank_id'],
                'cheque_number'  => $firstCheque['cheque_number'],
                'cheque_date'    => $firstCheque['cheque_date'] ?? null,
                'bank_status'    => $firstCheque['bank_status'],

                'payee'          => $validated['payee'],
                'amount'         => $validated['amount'],
                'remarks'        => $validated['remarks'] ?? null,
                'status'         => 'Unliquidated',
                'user_id'        => $user->id,
            ]);

            // Save ALL cheque rows
            foreach ($validated['bank_cheques'] as $row) {

                FundTransferBankCheque::create([

                    'fund_transfer_id' => $record->id,
                    'bank_id'          => $row['bank_id'],
                    'cheque_number'    => $row['cheque_number'],
                    'cheque_date'      => $row['cheque_date'] ?? null,
                    'bank_status'      => $row['bank_status'],
                    'amount'           => $row['amount'],

                ]);

                // Mark cheque as used
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

            (new \App\Http\Controllers\Library\BankLibraryController())
                ->updateBanksStatus();

            DB::commit();

            AdminAuthController::logUserAction(
                $user,
                'Created Fund Transfer',
                sprintf(
                    '#%s [%s] payee "%s" amount ₱%s (%d cheque(s))',
                    $record->dv_number,
                    strtoupper($record->type),
                    $record->payee,
                    number_format($record->amount, 2),
                    count($validated['bank_cheques'])
                )
            );

            return response()->json([
                'status' => true,
                'message' => 'Fund Transfer created successfully.',
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

    // GET /api/barangay/fund-transfers/{id}
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $record = FundTransfer::with(['bankCheques.bank', 'barangay'])
            ->where('barangay_id', $user->barangay_id)
            ->findOrFail($id);

        return response()->json(['status' => true, 'data' => $this->format($record)]);
    }

    // POST /api/barangay/fund-transfers/{id}/void-request
    public function requestVoid(Request $request, $id)
    {
        $request->validate(['remarks' => 'required|string|max:500']);
        $user = $request->user();

        $record = FundTransfer::where('barangay_id', $user->barangay_id)->findOrFail($id);

        if (!in_array($record->status, ['Unliquidated', 'Partial'])) {
            return response()->json(['status' => false, 'message' => 'Cannot void this record'], 400);
        }

        $record->update(['status' => 'Void Requested', 'remarks' => $request->remarks]);

        AdminAuthController::logUserAction(
            $user,
            'Fund Transfer Void Requested',
            sprintf('#%s requested void', $record->dv_number)
        );

        return response()->json(['status' => true, 'message' => 'Void request submitted']);
    }

    // POST /api/barangay/fund-transfers/{id}/void-direct
    public function voidDirect(Request $request, $id)
    {
        $request->validate(['remarks' => 'required|string|max:500']);
        $user = $request->user();

        $position = strtolower(optional($user->position)->name ?? '');
        $canVoid = str_contains($position, 'captain') || str_contains($position, 'chairperson');

        if (!$canVoid) {
            return response()->json(['status' => false, 'message' => 'Only Captain/Chairperson can void directly'], 403);
        }

        $record = FundTransfer::where('barangay_id', $user->barangay_id)->findOrFail($id);

        if (!in_array($record->status, ['Unliquidated', 'Partial'])) {
            return response()->json(['status' => false, 'message' => 'Cannot void this record'], 400);
        }

        $record->update(['status' => 'Voided', 'remarks' => $request->remarks]);

        $booklets = LibBooklet::where('bank_id', $record->bank_id)->pluck('id');
        $cheque = LibCheque::where('cheque_number', $record->cheque_number)
            ->whereIn('booklet_id', $booklets)->first();
        if ($cheque) {
            $cheque->update(['status' => 'void']);
            (new \App\Http\Controllers\Library\BankLibraryController())->updateBanksStatus();
        }

        AdminAuthController::logUserAction(
            $user,
            'Fund Transfer Voided Directly',
            sprintf('#%s voided', $record->dv_number)
        );

        return response()->json(['status' => true, 'message' => 'Voided successfully']);
    }

    // POST /api/barangay/fund-transfers/{id}/void-approve
    public function approveVoid(Request $request, $id)
    {
        $user = $request->user();
        $position = strtolower(optional($user->position)->name ?? '');
        $canApprove = str_contains($position, 'captain') || str_contains($position, 'chairperson');

        if (!$canApprove) {
            return response()->json(['status' => false, 'message' => 'Only Captain/Chairperson can approve'], 403);
        }

        $record = FundTransfer::where('barangay_id', $user->barangay_id)->findOrFail($id);

        if ($record->status !== 'Void Requested') {
            return response()->json(['status' => false, 'message' => 'Status must be Void Requested'], 400);
        }

        $record->update(['status' => 'Voided']);

        $booklets = LibBooklet::where('bank_id', $record->bank_id)->pluck('id');
        $cheque = LibCheque::where('cheque_number', $record->cheque_number)
            ->whereIn('booklet_id', $booklets)->first();
        if ($cheque) {
            $cheque->update(['status' => 'void']);
            (new \App\Http\Controllers\Library\BankLibraryController())->updateBanksStatus();
        }

        AdminAuthController::logUserAction(
            $user,
            'Fund Transfer Void Approved',
            sprintf('#%s void approved', $record->dv_number)
        );

        return response()->json(['status' => true, 'message' => 'Void approved']);
    }

    // POST /api/barangay/fund-transfers/{id}/void-reject
    public function rejectVoid(Request $request, $id)
    {
        $request->validate(['remarks' => 'required|string|max:500']);
        $user = $request->user();
        $position = strtolower(optional($user->position)->name ?? '');
        $canReject = str_contains($position, 'captain') || str_contains($position, 'chairperson');

        if (!$canReject) {
            return response()->json(['status' => false, 'message' => 'Only Captain/Chairperson can reject'], 403);
        }

        $record = FundTransfer::where('barangay_id', $user->barangay_id)->findOrFail($id);

        if ($record->status !== 'Void Requested') {
            return response()->json(['status' => false, 'message' => 'Status must be Void Requested'], 400);
        }

        $record->update(['status' => 'Unliquidated', 'rejection_remarks' => $request->remarks]);

        AdminAuthController::logUserAction(
            $user,
            'Fund Transfer Void Rejected',
            sprintf('#%s void rejected', $record->dv_number)
        );

        return response()->json(['status' => true, 'message' => 'Void rejected']);
    }

    private function format(FundTransfer $d): array
    {
        return [
            'id'            => $d->id,
            'date'          => $d->date,
            'dv_number'     => $d->dv_number,

            'bank_cheques' => $d->bankCheques->map(function ($c) {
                return [
                    'id' => $c->id,
                    'bank_id' => $c->bank_id,
                    'bank_name' => optional($c->bank)->bank_name,
                    'cheque_number' => $c->cheque_number,
                    'cheque_date' => $c->cheque_date,
                    'bank_status' => $c->bank_status,
                    'amount' => $c->amount,
                ];
            }),

            'payee'         => $d->payee,
            'dvAmount'      => $d->amount,
            'dv_amount'     => $d->amount,
            'amount'        => $d->amount,
            'type'          => $d->type,
            'status'        => $d->status,
            'remarks'       => $d->remarks,
            'rejection_remarks' => $d->rejection_remarks ?? null,
            'created_at'    => $d->created_at,
            'updated_at'    => $d->updated_at,
        ];
    }
}
