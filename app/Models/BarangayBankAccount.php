<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BarangayBankAccount extends Model
{
    protected $fillable = [
        'barangay_setup_id',
        'bank_id',
        'account_number',
        'is_default',
    ];

    public function setup()
    {
        return $this->belongsTo(BarangaySetup::class,'barangay_setup_id');
    }

    public function bank()
    {
        return $this->belongsTo(LibBank::class);
    }
}
