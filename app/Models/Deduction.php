<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deduction extends Model
{
    protected $fillable = [

        'disbursement_id',

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

        'net_amount'

    ];

    protected $casts = [
        'divisor' => 'decimal:2',
        'vat_percent' => 'decimal:2',
        'ewt_percent' => 'decimal:2',
        'gross_vat_inc' => 'decimal:2',
        'gross_vat_exc' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
    ];

    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class);
    }
}
