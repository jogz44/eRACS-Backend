<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbcAdviceItem extends Model
{
    protected $table = 'pbc_advice_items';

    protected $fillable = [

        'pbc_advice_id',

        'disbursement_id',

        'cont_disbursement_id',

        'bank_cheque_id',

        'cont_bank_cheque_id',

    ];

    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class);
    }

    public function bankCheque()
    {
        return $this->belongsTo(BankCheque::class, 'bank_cheque_id');
    }

    public function contDisbursement()
    {
        return $this->belongsTo(
            ContDisbursement::class,
            'cont_disbursement_id'
        );
    }

    public function contBankCheque()
    {
        return $this->belongsTo(
            ContBankCheque::class,
            'cont_bank_cheque_id'
        );
    }
}
