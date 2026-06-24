<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DeductionCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [

            [
                'code'=>'W010',
                'label'=>'W010 - GOODS',
                'deduction_type'=>'businessTax',
                'divisor'=>1,
                'vat_percent'=>3,
                'ewt_percent'=>1
            ],

            [
                'code'=>'W020',
                'label'=>'W020 - SERVICES / RENTALS',
                'deduction_type'=>'businessTax',
                'divisor'=>1,
                'vat_percent'=>3,
                'ewt_percent'=>1
            ],

            [
                'code'=>'W080',
                'label'=>'W080 - PERCENTAGE',
                'deduction_type'=>'businessTax',
                'divisor'=>1,
                'vat_percent'=>3,
                'ewt_percent'=>2
            ],

            [
                'code'=>'W640',
                'label'=>'W640 - GOODS',
                'deduction_type'=>'EWT',
                'divisor'=>1,
                'vat_percent'=>3,
                'ewt_percent'=>1
            ],

            [
                'code'=>'W157',
                'label'=>'W157 - SERVICES',
                'deduction_type'=>'EWT',
                'tax_type'=>'percentage',
                'divisor'=>1,
                'vat_percent'=>3,
                'ewt_percent'=>2
            ],

            [
                'code'=>'W100',
                'label'=>'W100 - RENTALS',
                'deduction_type'=>'EWT',
                'tax_type'=>'percentage',
                'divisor'=>1,
                'vat_percent'=>3,
                'ewt_percent'=>5
            ],

            [
                'code'=>'W000',
                'label'=>'W000 - VAT Withheld',
                'deduction_type'=>'businessTax',
                'tax_type'=>'percentage',
                'divisor'=>1,
                'vat_percent'=>12,
                'ewt_percent'=>0
            ],
        ];

        foreach ($rows as $row) {
            \App\Models\LibDeductionCode::updateOrCreate(
                ['code'=>$row['code']],
                $row
            );
        }
    }
}
