<?php

namespace App\Http\Controllers;

use App\Models\BirRemittance;
use App\Models\BirBankCheque;
use App\Models\LibBooklet;
use App\Models\LibCheque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AdminAuthController;

class BirRemittanceController extends Controller
{
    // GET /api/barangay/bir-remittances
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = BirRemittance::with(['bankCheques.bank', 'barangay'])
            ->where('barangay_id', $user->barangay_id);

        $year = $request->input('year', now()->year);
        $query->whereYear('date', $year);

        $items = $query->orderByDesc('date')->get()->map(fn($d) => $this->format($d));

        return response()->json(['status' => true, 'data' => $items]);
    }

    // POST /api/barangay/bir-remittances
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date'          => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/',

            'dv_number' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('disbursements', 'dv_number'),
            ],

            'dv_amount' => 'required|numeric|min:0.01',

            'bank_cheques' => 'required|array|min:1',
            'bank_cheques.*.bank_id' => 'required|exists:lib_banks,id',
            'bank_cheques.*.cheque_number' => 'required|string',
            'bank_cheques.*.cheque_date' => 'nullable|date',
            'bank_cheques.*.bank_status' => 'required|in:online,offline',
            'bank_cheques.*.amount' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {

            $user = $request->user();

            [$dd, $mm, $yyyy] = explode('/', $validated['date']);
            $formattedDate = "$yyyy-$mm-$dd";

            $firstCheque = $validated['bank_cheques'][0];

            // Create parent remittance
            $record = BirRemittance::create([
                'barangay_id' => $user->barangay_id,
                'date' => $formattedDate,
                'dv_number' => $validated['dv_number'],

                // Required by current table
                'bank_id' => $firstCheque['bank_id'],
                'cheque_number' => $firstCheque['cheque_number'],
                'cheque_date' => $firstCheque['cheque_date'] ?? null,
                'bank_status' => $firstCheque['bank_status'],

                'payee' => 'Bureau of Internal Revenue',
                'dv_amount' => $validated['dv_amount'],
                'status' => 'Unliquidated',
                'user_id' => $user->id,
            ]);

            // Save ALL bank cheques
            foreach ($validated['bank_cheques'] as $row) {

                BirBankCheque::create([
                    'bir_remittance_id' => $record->id,
                    'bank_id' => $row['bank_id'],
                    'cheque_number' => $row['cheque_number'],
                    'cheque_date' => $row['cheque_date'] ?? null,
                    'bank_status' => $row['bank_status'],
                    'amount' => $row['amount'],
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
                ->updateBanksStatus();

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
        $request->validate(['remarks' => 'required|string|max:500']);
        $user = $request->user();

        $position = strtolower(optional($user->position)->name ?? '');
        $canVoid = str_contains($position, 'captain') || str_contains($position, 'chairperson');

        if (!$canVoid) {
            return response()->json(['status' => false, 'message' => 'Only Captain/Chairperson can void directly'], 403);
        }

        $record = BirRemittance::where('barangay_id', $user->barangay_id)->findOrFail($id);

        if (!in_array($record->status, ['Unliquidated', 'Partial'])) {
            return response()->json(['status' => false, 'message' => 'Cannot void this record'], 400);
        }

        $record->update(['status' => 'Voided', 'remarks' => $request->remarks]);

        foreach ($record->bankCheques as $bankCheque) {

            $booklets = LibBooklet::where(
                'bank_id',
                $bankCheque->bank_id
            )->pluck('id');

            $libCheque = LibCheque::where(
                'cheque_number',
                $bankCheque->cheque_number
            )
            ->whereIn('booklet_id', $booklets)
            ->first();

            if ($libCheque) {

                $libCheque->update([
                    'status' => 'void'
                ]);

            }
        }

        (new \App\Http\Controllers\Library\BankLibraryController())
            ->updateBanksStatus();

        AdminAuthController::logUserAction($user, 'BIR Voided Directly',
            sprintf('#%s voided', $record->dv_number));

        return response()->json(['status' => true, 'message' => 'Voided successfully']);
    }

    // POST /api/barangay/bir-remittances/{id}/void-approve
    public function approveVoid(Request $request, $id)
    {
        $user = $request->user();
        $position = strtolower(optional($user->position)->name ?? '');
        $canApprove = str_contains($position, 'captain') || str_contains($position, 'chairperson');

        if (!$canApprove) {
            return response()->json(['status' => false, 'message' => 'Only Captain/Chairperson can approve'], 403);
        }

        $record = BirRemittance::where('barangay_id', $user->barangay_id)->findOrFail($id);

        if ($record->status !== 'Void Requested') {
            return response()->json(['status' => false, 'message' => 'Status must be Void Requested'], 400);
        }

        $record->update(['status' => 'Voided']);

        foreach ($record->bankCheques as $bankCheque) {

            $booklets = LibBooklet::where(
                'bank_id',
                $bankCheque->bank_id
            )->pluck('id');

            $libCheque = LibCheque::where(
                'cheque_number',
                $bankCheque->cheque_number
            )
            ->whereIn('booklet_id', $booklets)
            ->first();

            if ($libCheque) {

                $libCheque->update([
                    'status' => 'void'
                ]);

            }
        }

        (new \App\Http\Controllers\Library\BankLibraryController())
            ->updateBanksStatus();

        AdminAuthController::logUserAction($user, 'BIR Void Approved',
            sprintf('#%s void approved', $record->dv_number));

        return response()->json(['status' => true, 'message' => 'Void approved']);
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

    //GET /api/barangay/bir-remittances/pending-tax-total
    // public function pendingTaxTotal(Request $request)
    // {
    //     $user = $request->user();
    //     // Sum unliquidated BIR remittances for this barangay as "pending tax"
    //     $total = BirRemittance::where('barangay_id', $user->barangay_id)
    //         ->whereIn('status', ['Unliquidated', 'Partial'])
    //         ->sum('dv_amount');

    //     return response()->json(['status' => true, 'pending_total' => (float) $total]);
    // }

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
