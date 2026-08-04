<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbcAdvice extends Model
{
    protected $table = 'pbc_advices';

    protected $fillable = [

        'barangay_id',

        'bank_id',

        'advice_no',

        'advice_date',

        'from_date',

        'to_date',

        'voucher_count',

        'total_amount',

        'created_by',

    ];

    public function items()
    {
        return $this->hasMany(PbcAdviceItem::class);
    }

    public function bank()
    {
        return $this->belongsTo(LibBank::class, 'bank_id');
    }
}
