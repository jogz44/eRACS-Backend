<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Deduction;

class LibDeductionCode extends Model
{
    protected $fillable = [
        'code',
        'label',
        'deduction_type',
        'tax_type',
        'divisor',
        'vat_percent',
        'ewt_percent'
    ];

    public function deductions()
    {
        return $this->hasMany(
            Deduction::class,
            'deduction_code_id'
        );
    }
}
