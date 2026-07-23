<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankExportFile extends Model
{
    protected $fillable = [

        'disbursement_id',
        'barangay_setup_id',
        'generated_by',

        'filename',
        'filepath',

        'is_exported',
        'exported_at',

    ];

    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class);
    }

    public function barangaySetup()
    {
        return $this->belongsTo(BarangaySetup::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(BarangayUser::class, 'generated_by');
    }
}
