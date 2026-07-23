<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundTransfer extends Model
{
    protected $fillable = [
        'barangay_id',
        'type',
        'fiscal_year_id',
        'date',
        'dv_number',
        'cheque_number',
        'cheque_date',
        'bank_id',

        'bank_status',
        
        'payee',
        'amount',
        'remarks',
        'status',
        'user_id',
    ];

    public function bank()
    {
        return $this->belongsTo(LibBank::class, 'bank_id');
    }

    public function barangay()
    {
        return $this->belongsTo(Barangay::class, 'barangay_id');
    }

    public function fiscalYear()
    {
        return $this->belongsTo(LibFiscalYear::class, 'fiscal_year_id');
    }

    public function user()
    {
        return $this->belongsTo(BarangayUser::class, 'user_id');
    }
}
