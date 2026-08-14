<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Barangay;
use App\Models\LibBank;
use App\Models\LibBooklet;
use App\Models\LibCheque;

class BookletAndChequeSeeder extends Seeder
{
    public function run(): void
    {
        //Configuration
        // Number of booklets to create for each bank
        $bookletsPerBank = 2;

        // Number of cheques to generate for each booklet
        $chequesPerBooklet = 100;

        //Loop through all barangays
        foreach (Barangay::all() as $barangay) {

            // Fetch all banks for this barangay
            $banks = LibBank::where('barangay_id', $barangay->id)->get();

            foreach ($banks as $bank) {

                //Create booklets
                for ($b = 1; $b <= $bookletsPerBank; $b++) {

                    // Random starting cheque number
                    $startingNumber = random_int(10000000, 99990000);

                    /*
                    | Example:
                    | startingNumber = 10000000
                    | chequesPerBooklet = 100
                    |
                    | endingNumber = 10000099
                    |
                    | Total = 100 cheques
                    */
                    $endingNumber = $startingNumber + $chequesPerBooklet - 1;

                    //Create booklet
                    $booklet = LibBooklet::create([
                        'bank_id'              => $bank->id,
                        'booklet_numb'         => "BK-{$barangay->id}-{$bank->id}-{$b}-" . rand(100, 999),
                        'starting_cheque_numb' => $startingNumber,
                        'ending_cheque_numb'   => $endingNumber,
                        'quantity'             => $quantity,
                        'status'               => 'unused',
                    ]);

                    //Generate cheques for this booklet
                    for ($num = $startingNumber; $num <= $endingNumber; $num++) {
                        LibCheque::create([
                            'booklet_id'    => $booklet->id,
                            'cheque_number' => $num,
                            'status'        => 'unused',
                        ]);
                    }
                }
            }
        }
    }
}
