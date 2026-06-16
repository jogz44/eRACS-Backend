<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TranExpenseDetail;
use App\Models\Disbursement;
use App\Models\TranAppropriation;
use App\Models\Barangay;

class TranExpenseDetailSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Barangay::all() as $barangay) {
            $disbursements = Disbursement::where('barangay_id', $barangay->id)->get();

            if ($disbursements->isEmpty()) {
                $this->command->warn("No disbursements found for {$barangay->name}. Skipping.");
                continue;
            }

            $appropriations = TranAppropriation::where('barangay_id', $barangay->id)
                ->where('status', 'committed')
                ->get();

            if ($appropriations->isEmpty()) {
                $this->command->warn("No committed appropriations found for {$barangay->name}. Skipping.");
                continue;
            }

            foreach ($disbursements as $disbursement) {
                $alreadyHasDetails = TranExpenseDetail::where('disbursement_id', $disbursement->id)->exists();

                if ($alreadyHasDetails) {
                    continue;
                }

                $amount = (float) $disbursement->dv_amount;

                if ($amount <= 0) {
                    continue;
                }

                $appropriation = $appropriations->random();

                TranExpenseDetail::create([ 
                    'disbursement_id' => $disbursement->id,
                    'appropriation_id' => $appropriation->id,
                    'amount' => $amount,
                    'particulars' => 'Migrated expense detail sample',
                    'bank_id' => $disbursement->bank_id,    
                    'cheque_number' => $disbursement->cheque_number,
                    'created_at' => $disbursement->created_at,
                    'updated_at' => $disbursement->updated_at,
                ]);
            }
        }
    }
}