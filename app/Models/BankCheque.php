<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankCheque extends Model
{
    protected $fillable = [

        'disbursement_id',

        'bank_id',

        'cheque_number',

        'cheque_date',

        'amount',

        'bank_status',

    ];

    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class);
    }

    public function bank()
    {
        return $this->belongsTo(LibBank::class);
    }
}
