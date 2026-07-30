<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BirBankCheque extends Model
{
    protected $fillable = [

        'bir_remittance_id',

        'bank_id',

        'cheque_number',

        'cheque_date',

        'bank_status',

        'amount'

    ];

    public function bank()
    {
        return $this->belongsTo(LibBank::class);
    }

    public function remittance()
    {
        return $this->belongsTo(BirRemittance::class);
    }
}
