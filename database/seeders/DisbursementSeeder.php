<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Disbursement;
use App\Models\Barangay;
use App\Models\LibBank;
use App\Models\LibBooklet;
use App\Models\LibCheque;
use App\Models\DisbursementOrDetail;
use App\Models\TranExpenseDetail;
use App\Models\TranAppropriation;
use Carbon\Carbon;
use Faker\Factory as Faker;

class DisbursementSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();
        $barangays = Barangay::all();
        $now = Carbon::now();
        $yy = $now->format('y');
        $mm = $now->format('m');
        $dvCounter = 1;
        // inside run()
        foreach ($barangays as $barangay) {
            $banks = LibBank::where('barangay_id', $barangay->id)->get();
            if ($banks->isEmpty()) continue;

            foreach ([2023, 2024, 2025] as $year) {
                $appropriations = TranAppropriation::where('barangay_id', $barangay->id)
                    ->where('status', 'committed')
                    ->where('amount', '>', 0)
                    ->whereHas('expenseClass', fn($q) => $q->whereHas('fiscalYear', fn($y) => $y->where('year', $year)))
                    ->get();

                if ($appropriations->isEmpty()) continue;


                $likePattern = 'DV-'.substr($year, -2).'-'.str_pad(now()->month, 2, '0', STR_PAD_LEFT).'-%';

                $lastDisbursement = Disbursement::where('dv_number', 'like', $likePattern)
                    ->orderByDesc('dv_number')
                    ->first();

                if ($lastDisbursement) {
                    $dv = $lastDisbursement->dv_number;
                    $segments = explode('-', $dv);
                    if (count($segments) === 4) {
                        $lastSegment = $segments[3];
                        $dvCounter = (int)$lastSegment;
                    }
                    $dvCounter++;
                }else{
                    $dvCounter = 1;
                }

                // exactly 6 disbursements per year
                for ($d = 0; $d < 18; $d++) {
                    $status = ($d%3 == 0) ? 'Liquidated' : (($d%3 == 1) ? 'Partial' : 'Unliquidated');
                    //$status = $faker->randomElement(['Unliquidated', 'Partial', 'Liquidated']);
                    $bank = $banks->random();

                    // cheque selection same as before...
                    $chequeNumber = null;
                    $cheque =null;
                    $bookletIds = LibBooklet::where('bank_id', $bank->id)->pluck('id');
                    if ($bookletIds->isNotEmpty()) {
                        $cheque = LibCheque::whereIn('booklet_id', $bookletIds)
                            ->where('status', 'unused')
                            ->orderBy('cheque_number')
                            ->first();
                        if ($cheque) {
                            $chequeNumber = $cheque->cheque_number;
                            $cheque->update(['status' => 'used']);
                        }
                    }
                    if (!$chequeNumber) continue;

                    $base = Carbon::create($year, $now->month, $now->day);
                    $startDate = $base->copy()->subMonths(8);
                    $createdAt = $faker->dateTimeBetween($startDate, $base);

                    $dvAmount = $faker->numberBetween(5, 20) * 1000;
                    $dvNumber = "DV-".substr($year, -2)."-".str_pad($now->month, 2, '0', STR_PAD_LEFT)."-" . str_pad($dvCounter, 3, '0', STR_PAD_LEFT);

                    $liquidatedAmount = null;
                    if ($status === 'Liquidated') {
                        $liquidatedAmount = $dvAmount;
                    } elseif ($status === 'Partial') {
                        $liquidatedAmount = (int) ($dvAmount * $faker->randomFloat(2, 0.3, 0.8));
                    }
                    $disb = Disbursement::create([
                        'barangay_id'       => $barangay->id,
                        'date'              => $createdAt->format('Y-m-d'),
                        'dv_number'         => $dvNumber,
                        'cheque_number'     => $chequeNumber,
                        'bank_id'           => $bank->id,
                        'payee'             => $faker->name,
                        'dv_amount'         => $dvAmount,
                        'status'            => $status,
                        'liquidated_amount' => $liquidatedAmount,
                        'liquidated_at'     => $liquidatedAmount ? $base: null,
                        'created_at'        => $createdAt,
                        'updated_at'        => $createdAt,
                    ]);
                    $cheque->update(['disbursement_id' => $disb->id]);

                    $this->seedExpenseDetails($faker, $disb->id, $appropriations, $dvAmount, Carbon::parse($startDate));

                    if ($status === 'Liquidated' || $status === 'Partial') {
                        $liq = $liquidatedAmount ?? 0;
                        $splits = $this->randomSplits($liq, $faker->numberBetween(1, 3));
                        $this->seedOrDetails($disb->id, $splits, Carbon::parse($startDate), $dvCounter);
                    }

                    $dvCounter++;
                }
            }
        }
    }

    private function seedExpenseDetails($faker, int $disbursementId, $appropriations, int $totalAmount, Carbon $baseDate): void
    {
        $particularsList = ['Office Supplies', 'Travel Expense', 'Fuel', 'Repairs', 'Meals and Snacks',
         'Training Fee', 'Honorarium', 'Internet Bill', 'Telephone Bill', 'Electricity Bill', 'Water Bill', 
         'Stationery', 'Printing Cost', 'Postage', 'Bank Charges', 'Software License', 'Consultancy Fee', 
         'Legal Services', 'Audit Fee', 'Seminar Expense', 'Insurance Premium', 'Medical Supplies', 'Uniforms',
          'Cleaning Materials', 'Construction Materials', 'Transportation Cost', 'Miscellaneous', 'Contingency Fund',
           'Rental Fee', 'Maintenance Cost'];

        $remaining = $totalAmount;
        $num = $faker->numberBetween(1, 4);
        $used = [];

        for ($i = 0; $i < $num && $remaining > 0; $i++) {
            $available = $appropriations->whereNotIn('id', $used);
            if ($available->isEmpty()) break;

            $appr = $available->random();
            $used[] = $appr->id;

            $amount = ($i === $num - 1) ? $remaining : $faker->numberBetween(100, $remaining);
            $amount = (int) floor($amount / 100) * 100;

            TranExpenseDetail::create([
                'disbursement_id'  => $disbursementId,
                'appropriation_id' => $appr->id,
                'amount'           => $amount,
                'particulars' => $faker->randomElement($particularsList),
                'created_at'       => $baseDate,
                'updated_at'       => $baseDate,
            ]);

            $remaining -= $amount;
        }

        if ($remaining > 0) {
            $appr = $appropriations->first();
            TranExpenseDetail::create([
                'disbursement_id'  => $disbursementId,
                'appropriation_id' => $appr->id,
                'amount'           => $remaining,
                'particulars'      => 'Adjustment entry',
                'created_at'       => $baseDate,
                'updated_at'       => $baseDate,
            ]);
        }
    }

    private function seedOrDetails(int $disbursementId, array $amounts, Carbon $baseDate, int $dvIndex): void
    {
        foreach ($amounts as $k => $amount) {
            $orDate = $baseDate->copy()->addDays($k + 1);
            DisbursementOrDetail::create([
                'disbursement_id' => $disbursementId,
                'or_date'         => $orDate->format('Y-m-d'),
                'or_number'       => 'OR-' . str_pad($dvIndex, 3, '0', STR_PAD_LEFT) . '-' . ($k + 1),
                'or_amount'       => $amount,
                'or_photo'        => 'or-photos/sample.png',
                'remarks'         => 'Seeded OR detail',
                'created_at'      => $orDate,
                'updated_at'      => $orDate,
            ]);
        }
    }

    private function randomSplits(int $total, int $parts): array
    {
        if ($total <= 0) return [];

        $splits = [];
        $remaining = $total;

        for ($i = 0; $i < $parts; $i++) {
            if ($i === $parts - 1) {
                $splits[] = $remaining;
            } else {
                $amount = rand(100, max(100, $remaining - ($parts - $i - 1) * 100));
                $amount = (int) floor($amount / 100) * 100;
                $splits[] = $amount;
                $remaining -= $amount;
            }
        }
        return $splits;
    }
}
