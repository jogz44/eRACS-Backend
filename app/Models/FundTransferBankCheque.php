<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundTransferBankCheque extends Model
{
    protected $fillable = [

        'fund_transfer_id',

        'bank_id',

        'cheque_number',

        'cheque_date',

        'bank_status',

        'amount',

    ];

    public function bank()
    {
        return $this->belongsTo(LibBank::class);
    }

    public function transfer()
    {
        return $this->belongsTo(FundTransfer::class, 'fund_transfer_id');
    }
}
