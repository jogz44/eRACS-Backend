<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
