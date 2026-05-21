<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ContDisbursement;
use App\Models\ContDisbursementOrDetail;
use App\Models\Barangay;
use App\Models\LibBank;
use Carbon\Carbon;
use Faker\Factory as Faker;

class ContDisbursementSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();
        $barangays = Barangay::all();

        foreach ($barangays as $barangay) {

            $banks = LibBank::where('barangay_id', $barangay->id)->get();
            if ($banks->isEmpty()) continue;

            for ($i = 1; $i <= 15; $i++) {

                $status = $faker->randomElement([
                    'Unliquidated', 'Partial', 'Liquidated'
                ]);

                $dvAmount = rand(5000, 20000);

                $liquidatedAmount = match ($status) {
                    'Liquidated' => $dvAmount,
                    'Partial' => rand(1000, $dvAmount - 1000),
                    default => null,
                };

                $date = Carbon::now()->subDays(rand(1, 300));

                $disb = ContDisbursement::create([
                    'barangay_id'       => $barangay->id,
                    'date'              => $date,
                    'dv_number'         => 'CDV-' . str_pad($i, 5, '0', STR_PAD_LEFT),
                    'cheque_number'     => 'CHK-' . rand(10000, 99999),
                    'bank_id'           => $banks->random()->id,
                    'payee'             => $faker->name,
                    'dv_amount'         => $dvAmount,
                    'status'            => $status,
                    'liquidated_amount' => $liquidatedAmount,
                    'liquidated_at'     => $liquidatedAmount ? $date : null,
                    'remarks'           => 'Seeded cont disbursement',
                ]);

                // ✅ Only create OR if may liquidation
                if ($liquidatedAmount) {
                    $this->seedOrDetails($disb->id, $liquidatedAmount, $date, $i);
                }
            }
        }
    }

    private function seedOrDetails($contDisbId, $total, $baseDate, $index)
    {
        $parts = rand(1, 3);
        $remaining = $total;

        for ($i = 0; $i < $parts; $i++) {

            $amount = ($i == $parts - 1)
                ? $remaining
                : rand(500, $remaining - 500);

            $amount = floor($amount / 100) * 100;
            $remaining -= $amount;

            ContDisbursementOrDetail::create([
                'cont_disbursement_id' => $contDisbId,
                'or_date'   => $baseDate->copy()->addDays($i),
                'or_number' => 'COR-' . str_pad($index, 5, '0', STR_PAD_LEFT) . '-' . ($i + 1),
                'or_amount' => $amount,
                'or_photo'  => 'or/sample.png',
                'remarks'   => 'Seeded OR detail',
            ]);
        }
    }
}