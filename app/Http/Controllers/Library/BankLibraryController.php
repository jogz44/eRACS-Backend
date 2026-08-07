<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\LibBank;
use App\Models\LibCheque;
use App\Models\LibBooklet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AdminAuthController;
use App\Models\Admin;

class BankLibraryController extends Controller
{
    /**
     * Get all banks for current barangay
     */
    public function getBanks(Request $request)
    {
        if ($request->is('api/admin/*')) {

            $query = LibBank::query();

            if ($request->filled('barangay_id')) {
                $query->where('barangay_id', $request->barangay_id);
                $this->updateBanksStatus($request->barangay_id);
            }

            $banks = $query
                ->withCount('booklets')
                ->get()
                ->map(function ($bank) {
                    return [
                        'id' => $bank->id,
                        'name' => $bank->bank_name,
                        'status' => ucfirst($bank->status),
                        'booklets_count' => $bank->booklets_count,
                        'barangay_id' => $bank->barangay_id,
                    ];
                });

            return response()->json($banks);
        }

        $user = Auth::guard('barangay')->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $barangayId = $user->barangay_id;

        $this->updateBanksStatus($barangayId);

        $banks = LibBank::where('barangay_id', $barangayId)
            ->withCount('booklets')
            ->get()
            ->map(function ($bank) {
                return [
                    'id' => $bank->id,
                    'name' => $bank->bank_name,
                    'status' => ucfirst($bank->status),
                    'booklets_count' => $bank->booklets_count,
                ];
            });

        return response()->json($banks);
    }

        /**
         * Create a new bank
         */
        public function createBank(Request $request)
        {
            $validated = $request->validate([
                'name' => 'required|string|min:3|max:255',
            ]);

            $bank = LibBank::create([
                'bank_name' => $validated['name'],
                'status' => 'unavailable',
                'barangay_id' => Auth::user()->barangay_id, // Add this line
            ]);


            AdminAuthController::logUserAction(Auth::guard('barangay')->user(),'Bank Creation','Bank '.$bank->bank_name.' has been created');

            $this->updateBanksStatus($barangayId);
            return response()->json([
                'id' => $bank->id,
                'name' => $bank->bank_name,
                'status' => ucfirst($bank->status),
                'cheques_count' => 0,
            ], 201);
        }
        public function updateBanksStatus($barangayId)
        {
            $banks = LibBank::where('barangay_id', $barangayId)->get();

            foreach ($banks as $bank) {

                $totalBooklets = $bank->booklets()->count();

                if ($totalBooklets === 0) {
                    $bank->status = 'unavailable';
                } else {

                    $booklets = LibBooklet::where('bank_id', $bank->id)->get();

                    foreach ($booklets as $booklet) {

                        $totalCheques = $booklet->cheques()->count();
                        $usedCheques = $booklet->cheques()
                            ->where('status', '!=', 'unused')
                            ->count();

                        if ($usedCheques == 0) {
                            $booklet->status = 'unused';
                        } elseif ($usedCheques == $totalCheques) {
                            $booklet->status = 'consumed';
                        } else {
                            $booklet->status = 'not all consumed';
                        }

                        $booklet->save();
                    }

                    $hasAvailable = $bank->booklets()
                        ->where('status', '!=', 'consumed')
                        ->exists();

                    $bank->status = $hasAvailable
                        ? 'available'
                        : 'consumed';
                }

                $bank->save();
            }
        }

        /**
         * Update a bank
         */
        public function updateBank(Request $request, LibBank $bank)
        {
            // Verify bank belongs to user's barangay
            if ($bank->barangay_id !== Auth::user()->barangay_id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'name' => 'required|string|min:3|max:255'
            ]);

            $oldName = $bank->bank_name;

            $bank->update([
                'bank_name' => $validated['name']
            ]);

            AdminAuthController::logUserAction(Auth::guard('barangay')->user(),'Bank Update','Bank rename from "'.$oldName.'" to "'.$bank->bank_name.'".');

        $this->updateBanksStatus($barangayId);

            return response()->json([
                'id' => $bank->id,
                'name' => $bank->bank_name,
            ]);
        }

        public function deleteBank(LibBank $bank)
        {
            // Verify bank belongs to user's barangay
            if ($bank->barangay_id !== Auth::user()->barangay_id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Check if bank has booklets before deleting
            if ($bank->booklets()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete bank with existing booklets'
                ], 422);
            }

            // Check if bank has disbursements before deleting
            if ($bank->disbursements()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete bank with existing disbursements'
                ], 422);
            }

            $bankName = $bank->bank_name;
            $bank->delete();

            AdminAuthController::logUserAction(Auth::guard('barangay')->user(), 'Bank Deletion', 'Bank ' . $bankName . ' has been deleted.');

            $this->updateBanksStatus($barangayId);
                return response()->json(['message' => 'Bank deleted successfully']);
        }

        /**
         * Get all cheques for a bank
         */
        public function getBookletCheques($bookletId)
        {
            try {
                // Convert to integer and validate
                $bookletId = (int)$bookletId;
                if ($bookletId <= 0) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid booklet ID'
                    ], 400);
                }

                $booklet = LibBooklet::with('bank')->findOrFail($bookletId);

                // Verify barangay access
                if ($booklet->bank->barangay_id != Auth::user()->barangay_id) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }

                $cheques = $booklet->cheques()
                    ->with('disbursement')
                    ->get()
                    ->map(function ($cheque) {
                        return [
                            'id' => $cheque->id,
                            'cheque_number' => $cheque->cheque_number,
                            'cheque_status' => $cheque->status,
                            'created_at' => $cheque->created_at->format('Y-m-d'),
                            'dvn' => $cheque->disbursement->dv_number ?? 'none',
                            'dvamount' => $cheque->disbursement->dv_amount ?? null,
                        ];
                    });

                return response()->json([
                    'status' => true,
                    'message' => 'Success',
                    'data' => $cheques,
                    'cheques' => $cheques
                ]);

            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                return response()->json([
                    'status' => false,
                    'message' => 'Booklet not found'
                ], 404);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => false,
                    'message' => 'Server error: ' . $e->getMessage()
                ], 500);
            }
        }

    public function getBankBooklets(LibBank $bank)
    {
        // Verify bank belongs to user's barangay
        if ($bank->barangay_id !== Auth::user()->barangay_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $booklets = $bank->booklets()
            ->get()
            ->map(function ($booklet) {
                $start = (int)$booklet->starting_cheque_numb;
                $end = (int)$booklet->ending_cheque_numb;
                $quantity = $end - $start + 1;
                return [
                    'id' => $booklet->id,
                    'date' => $booklet->created_at->format('Y-m-d'), // Map to 'date'
                    'booklet_numb' => $booklet->booklet_numb,
                    'starting_cheque_numb' => $booklet->starting_cheque_numb,
                    'ending_cheque_numb' => $booklet->ending_cheque_numb,
                    'quantity' => $quantity,
                    'status' => $booklet->status,

                ];
            });

        return response()->json([
            'booklets' => $booklets
        ]);
    }


        public function getAvailableBookletCheques(Request $request, $bankId){
            // Get all unused booklets and every unused cheques for the selected bank
            $bank = LibBank::where('id',$bankId);

            //get first booklet of the bank filtered by booklet_numb
            $booklet=LibBooklet::where('bank_id',$bankId)
                ->where(function ($query) {
                    $query->where('status', 'unused')
                        ->orWhere('status', 'not all consumed');
                })
                ->orderBy('booklet_numb','asc')
                ->first();
            // get first cheque of the booklet filtered by cheque_number
            if($booklet){
                $cheque = $booklet->cheques()
                    ->where('status', 'unused')
                    ->get()
                    ->map(function ($cheque) {
                        return [
                            'id' => $cheque->id,
                            'cheque_number' => $cheque->cheque_number,
                            'status' => $cheque->status,
                        ];
                    });
                return response()->json([
                    'status' => true,
                    'message' => 'Available booklet and cheques retrieved successfully',
                    'data' => [
                        'id' => $booklet->id,
                        'date' => $booklet->created_at->format('Y-m-d'),
                        'booklet_numb' => $booklet->booklet_numb,
                        'quantity' => (int)$booklet->ending_cheque_numb - (int)$booklet->starting_cheque_numb + 1,
                        'starting_cheque_numb' => $booklet->starting_cheque_numb,
                        'ending_cheque_numb' => $booklet->ending_cheque_numb,
                        'status' => $booklet->status,
                        'cheque' => $cheque,
                        'stever'=>$bankId,
                        'steve'=>$booklet
                    ],
                ]);
            }else{
                return response()->json([
                    'status' => false,
                    'message' => 'No available booklets found for this bank',
                    'data' => null,
                ]);
            }
        }

    public function createBooklet(Request $request, LibBank $bank)
    {
        // Verify bank belongs to user's barangay
        if ($bank->barangay_id !== Auth::user()->barangay_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'booklet_numb' => 'required|string',
            'starting_cheque_numb' => 'required|string|size:8|regex:/^[0-9]+$/',
            'quantity' => 'required|integer|min:1|max:50',
        ]);

        // Convert to integers for validation
        $bookletNumb = (int)$validated['booklet_numb'];
        $start = (int)$validated['starting_cheque_numb'];
        $quantity = (int)$validated['quantity'];

        // Calculate ending cheque number
        $end = $start + $quantity - 1;

        // Validate range
        if ($quantity > 50) {
            return response()->json(['message' => 'Maximum 50 cheques per booklet'], 422);
        }

        // Start database transaction
        DB::beginTransaction();
        try {
            // Create the booklet first (without cheques)
            $booklet = $bank->booklets()->create([
                //'booklet_numb' => $validated['starting_cheque_numb'] . '-' . $validated['ending_cheque_numb'],
                'booklet_numb' => $validated['booklet_numb'],
                'starting_cheque_numb' => $validated['starting_cheque_numb'],
                'ending_cheque_numb' => $end,
                'quantity' => $validated['quantity'],
                'status' => 'unused',
            ]);

            // Generate and validate cheque numbers one by one
            for ($i = $start; $i <= $end; $i++) {
                $chequeNumber = str_pad($i, 8, '0', STR_PAD_LEFT);

                // Check if this cheque number exists in ANY booklet of THIS bank
                $exists = LibCheque::whereHas('booklet', function($query) use ($bank) {
                        $query->where('bank_id', $bank->id);
                    })
                    ->where('cheque_number', $chequeNumber)
                    ->exists();

                if ($exists) {
                    throw new \Exception("Cheque number {$chequeNumber} already exists in this bank");
                }

                // Create cheque with original 8-digit number
                $booklet->cheques()->create([
                    'cheque_number' => $chequeNumber,
                    'status' => 'unused',
                ]);
            }

            DB::commit();

            AdminAuthController::logUserAction(Auth::guard('barangay')->user(),
            'Booklet Creation', 'Booklet ' . $booklet->booklet_numb .
            ' has been created with ' . $quantity . ' cheques in Bank '. $bank->bank_name);
            $this->updateBanksStatus($barangayId);

            return response()->json([
                'id' => $booklet->id,
                'booklet_numb' => $booklet->booklet_numb,
                'starting_cheque_numb' => $booklet->starting_cheque_numb,
                'ending_cheque_numb' => $booklet->ending_cheque_numb,
                'quantity' => $quantity,
                'status' => $booklet->status,
                'created_at' => $booklet->created_at->format('Y-m-d'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create booklet: ' . $e->getMessage()
            ], 500);
        }
    }

}
