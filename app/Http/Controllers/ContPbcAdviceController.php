<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\PbcAdvice;
use App\Models\PbcAdviceItem;
use App\Models\ContDisbursement;
use App\Models\ContBankCheque;
use App\Models\Admin;


class ContPbcAdviceController extends Controller
{
    //GET api/barangay/pbc-advices
    public function index(Request $request)
    {
        $barangayId = $this->resolveBarangay($request);

        $records = PbcAdvice::with('bank')
            ->where('barangay_id', $barangayId)
            ->where('type', 'continuing')
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
            $barangayId = $this->resolveBarangay($request);

            // Prevent duplicate report for same bank/date range
            $existing = PbcAdvice::where('barangay_id', $barangayId)
                ->where('type', 'continuing')
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

           $bankCheques = ContBankCheque::with([
                    'contDisbursement',
                    'bank'
                ])
                ->join('barangay_bank_accounts as bba', function ($join) {
                    $join->on('cont_bank_cheques.bank_id', '=', 'bba.bank_id');
                })
                ->where('cont_bank_cheques.bank_id', $request->bank_id)
                ->where('bba.bank_status', 'offline')
                ->whereBetween('cont_bank_cheques.cheque_date', [
                    $request->from_date,
                    $request->to_date
                ])
                ->whereHas('contDisbursement', function ($q) use ($barangayId) {
                    $q->withoutGlobalScopes()
                    ->where('barangay_id', $barangayId);
                })
                ->select('cont_bank_cheques.*')
                ->orderBy('cont_bank_cheques.cheque_date')
                ->orderBy('cont_bank_cheques.cheque_number')
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
                'type'          => 'continuing',
                'advice_no'     => $adviceNo,
                'advice_date'   => $today,
                'from_date'     => $request->from_date,
                'to_date'       => $request->to_date,
                'voucher_count' => $bankCheques->pluck('cont_disbursement_id')->unique()->count(),
                'total_amount'  => $bankCheques->sum('amount'),
                'created_by'    => $user->id,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Included Disbursements
            |--------------------------------------------------------------------------
            */

            foreach ($bankCheques as $cheque) {

                PbcAdviceItem::create([
                    'pbc_advice_id' => $advice->id,
                    'cont_disbursement_id' => $cheque->cont_disbursement_id,
                    'cont_bank_cheque_id' => $cheque->id,
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
        $barangayId = $this->resolveBarangay($request);

        $advice = PbcAdvice::with([
            'bank',
            'items.bankCheque.bank',
            'items.disbursement',
            'items.contBankCheque.bank',
            'items.contDisbursement',
        ])
        ->where('barangay_id', $barangayId)
        ->where('type', 'continuing')
        ->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => $advice
        ]);
    }

    //DELETE api/barangay/pbc-advices/{id}
    public function destroy(Request $request, $id)
    {
        $barangayId = $this->resolveBarangay($request);

        $advice = PbcAdvice::where(
            'barangay_id',
            $barangayId
        )
        ->where('type', 'continuing')
        ->findOrFail($id);

        $advice->items()->delete();

        $advice->delete();

        return response()->json([
            'status'=>true,
            'message'=>'Advice deleted.'
        ]);
    }

    //Resolve the barangay ID depending on the authenticated user.
    private function resolveBarangay(Request $request)
    {
        // Admin API routes
        if ($request->routeIs('admin.*') || $request->is('api/admin/*')) {

            if (!$request->filled('barangay_id')) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'status' => false,
                        'message' => 'Barangay is required.'
                    ], 422)
                );
            }

            return $request->barangay_id;
        }

        // Barangay API routes
        $user = Auth::user();

        if (!$user || !isset($user->barangay_id)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated.'
                ], 401)
            );
        }

        return $user->barangay_id;
    }
}
