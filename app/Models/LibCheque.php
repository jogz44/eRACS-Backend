<?php
// app/Models/LibCheque.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\LibBank;
use App\Models\LibBooklet;
use App\Models\Disbursement;

class LibCheque extends Model
{
    protected $table = 'lib_cheque';

    protected $fillable = [
        'bank_id',
        'booklet_id',
        'cheque_number',
        'cheque_date',
        'status',
        'disbursement_id'
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(LibBank::class);
    }

    public function booklet(): BelongsTo
    {
        return $this->belongsTo(LibBooklet::class, 'booklet_id');
    }
    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class, 'disbursement_id');
    }
}
