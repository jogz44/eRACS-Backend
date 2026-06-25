<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisteredPayee extends Model
{
    protected $fillable = [
        'barangay_id',
        'firstname',
        'middlename',
        'lastname',
        'payee_name',
        'payee2_name',
        'type',
        'address',
        'zip_code',
        'taxpayer_type',
        'tin_number',
        'description',
        'status',
    ];

    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }
}
