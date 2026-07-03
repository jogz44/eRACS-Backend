<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContBankCheque extends Model
{
    use HasFactory;

    protected $table = 'cont_bank_cheques';

    protected $fillable = [
        'cont_disbursement_id',
        'bank_id',
        'cheque_number',
        'cheque_date',
        'amount',
    ];

    public function contDisbursement()
    {
        return $this->belongsTo(
            ContDisbursement::class,
            'cont_disbursement_id'
        );
    }

    public function bank()
    {
        return $this->belongsTo(
            LibBank::class,
            'bank_id'
        );
    }
}
