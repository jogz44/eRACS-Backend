<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContDeduction extends Model
{
    use HasFactory;

    protected $table = 'cont_deductions';

    protected $fillable = [
        'cont_disbursement_id',
        'deduction_code_id',

        'deduction_type',
        'tax_type',
        'code',

        'divisor',
        'vat_percent',
        'ewt_percent',

        'description',

        'gross_vat_inc',
        'gross_vat_exc',

        'deduction_amount',
        'net_amount',
    ];

    public function contDisbursement()
    {
        return $this->belongsTo(
            ContDisbursement::class,
            'cont_disbursement_id'
        );
    }

    public function deductionCode()
    {
        return $this->belongsTo(
            LibDeductionCode::class,
            'deduction_code_id'
        );
    }
}
