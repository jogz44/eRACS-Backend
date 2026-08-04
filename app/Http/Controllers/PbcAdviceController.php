<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\PbcAdvice;
use App\Models\PbcAdviceItem;
use App\Models\Disbursement;
use App\Models\BankCheque;

class PbcAdviceController extends Controller
{
    //GET api/barangay/pbc-advices
    public function index(Request $request)
    {
        $user = $request->user();

        $barangayId = $request->barangay_id;

        if (!$barangayId) {
            $barangayId = $user->barangay_id;
        }

        $records = PbcAdvice::with('bank')
            ->where('barangay_id', $barangayId)
            ->orderByDesc('advice_date')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $records
        ]);
    }

    // POST api/barangay/pbc-advices
    public function store(Request $request)
    {
        $request->validate([
            'barangay_id' => 'nullable|exists:barangays,id',
            'advice_no'   => 'nullable|string',
            'from_date'   => 'required|date',
            'to_date'     => 'required|date|after_or_equal:from_date',
            'bank_id'     => 'required|exists:lib_banks,id',
        ]);

        DB::beginTransaction();

        try {

            $user = $request->user();

            $barangayId = $request->barangay_id ?: $user->barangay_id;

            // Prevent duplicate report for same bank/date range
            $existing = PbcAdvice::where('barangay_id', $barangayId)
                ->where('bank_id', $request->bank_id)
                ->whereDate('from_date', $request->from_date)
                ->whereDate('to_date', $request->to_date)
                ->first();

            if ($existing) {

                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'A PBC Advice already exists for this bank and date range.'
                ], 422);
            }

            $today = now();

            /*
            |--------------------------------------------------------------------------
            | Generate Advice Number
            | Format: YY-MM-00001
            | Resets every month PER BARANGAY
            |--------------------------------------------------------------------------
            */
            $prefix = $today->format('y-m');

            $lastAdvice = PbcAdvice::where('barangay_id', $barangayId)
                ->whereYear('advice_date', $today->year)
                ->whereMonth('advice_date', $today->month)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($lastAdvice) {

                $parts = explode('-', $lastAdvice->advice_no);

                $sequence = intval(end($parts)) + 1;

            } else {

                $sequence = 1;

            }

            $adviceNo = sprintf('%s-%05d', $prefix, $sequence);

            /*
            |--------------------------------------------------------------------------
            | Fetch Offline Bank Cheques
            |--------------------------------------------------------------------------
            */

            $bankCheques = BankCheque::with('disbursement')
                ->join('barangay_bank_accounts as bba', function ($join) {
                    $join->on('bank_cheques.bank_id', '=', 'bba.bank_id');
                })
                ->where('bank_cheques.bank_id', $request->bank_id)
                ->where('bba.bank_status', 'offline')
                ->whereBetween('bank_cheques.cheque_date', [
                    $request->from_date,
                    $request->to_date
                ])
                ->whereHas('disbursement', function ($q) use ($barangayId) {
                    $q->withoutGlobalScopes()
                    ->where('barangay_id', $barangayId);
                })
                ->select('bank_cheques.*')
                ->get();

            if ($bankCheques->isEmpty()) {

                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'No offline cheques found for the selected period.'
                ],422);
            }

            /*
            |--------------------------------------------------------------------------
            | Create Advice Header
            |--------------------------------------------------------------------------
            */

            $advice = PbcAdvice::create([
                'barangay_id'   => $barangayId,
                'bank_id'       => $request->bank_id,
                'advice_no'     => $adviceNo,
                'advice_date'   => $today,
                'from_date'     => $request->from_date,
                'to_date'       => $request->to_date,
                'voucher_count' => $bankCheques->pluck('disbursement_id')->unique()->count(),
                'total_amount' => $bankCheques->sum('amount'),
                'created_by'    => $user->id,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Included Disbursements
            |--------------------------------------------------------------------------
            */

            foreach ($bankCheques as $cheque) {

                PbcAdviceItem::create([
                    'pbc_advice_id'  => $advice->id,
                    'disbursement_id'=> $cheque->disbursement_id,
                    'bank_cheque_id' => $cheque->id,
                ]);

            }

            DB::commit();

            AdminAuthController::logUserAction(
                $user,
                'Generated PBC Advice',
                sprintf(
                    'Advice No. %s | %d voucher(s) | Total ₱%s',
                    $advice->advice_no,
                    $advice->voucher_count,
                    number_format($advice->total_amount, 2)
                )
            );

            return response()->json([
                'status' => true,
                'message' => 'PBC Advice successfully generated.',
                'data' => $advice
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //GET api/barangay/pbc-advices/{id}
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $barangayId = $request->barangay_id;

        if (!$barangayId) {
            $barangayId = $user->barangay_id;
        }

        $advice = PbcAdvice::with([
            'bank',
            'items.bankCheque.bank',
            'items.disbursement'
        ])
        ->where('barangay_id', $barangayId)
        ->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => $advice
        ]);
    }

    //DELETE api/barangay/pbc-advices/{id}
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $barangayId = $request->barangay_id;

        if (!$barangayId) {
            $barangayId = $user->barangay_id;
        }

        $advice = PbcAdvice::where(
            'barangay_id',
            $barangayId
        )
        ->findOrFail($id);

        $advice->items()->delete();

        $advice->delete();

        return response()->json([
            'status'=>true,
            'message'=>'Advice deleted.'
        ]);
    }
}
