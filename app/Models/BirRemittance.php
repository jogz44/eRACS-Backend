<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BirRemittance extends Model
{
    protected $fillable = [
        'barangay_id',
        'date',
        'dv_number',
        'ref_dv_number',
        'cheque_number',
        'cheque_date',
        'bank_id',

        'bank_status',
        
        'payee',
        'dv_amount',
        'liquidated_amount',
        'status',
        'user_id',
        'liquidated_at',
        'remarks',
        'rejection_remarks',
    ];

    public function bank()
    {
        return $this->belongsTo(LibBank::class, 'bank_id');
    }

    public function barangay()
    {
        return $this->belongsTo(Barangay::class, 'barangay_id');
    }

    public function user()
    {
        return $this->belongsTo(BarangayUser::class, 'user_id');
    }
}
