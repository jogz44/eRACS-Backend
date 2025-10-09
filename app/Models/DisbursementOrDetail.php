<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisbursementOrDetail extends Model
{
    use HasFactory;

    protected $table = 'disbursement_or_details';

    protected $fillable = [
        'disbursement_id',
        'or_date',
        'or_number',
        'or_amount',
        'ref_or_amount',
        'or_photo',
        'remarks',
    ];

    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class);
    }
} 