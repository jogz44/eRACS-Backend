<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContDisbursementOrDetail extends Model
{
    protected $table = 'cont_disbursement_or_details';

    protected $fillable = [
        'cont_disbursement_id',
        'or_date',
        'or_number',
        'or_amount',
        'or_photo',
        'remarks',
    ];

    protected $casts = [
        'or_date' => 'date',
        'or_amount' => 'decimal:2',
    ];

    public function contDisbursement()
    {
        return $this->belongsTo(ContDisbursement::class, 'cont_disbursement_id');
    }
}