<?php
// app/Models/LibBank.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class LibBank extends Model
{

    protected $table = 'lib_banks';

    protected $fillable = [
         'bank_name',
    'status',
    'barangay_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function cheques(): HasManyThrough
{
    return $this->hasManyThrough(
        LibCheque::class,
        LibBooklet::class,
        'bank_id', // Foreign key on booklets table
        'booklet_id', // Foreign key on cheques table
        'id', // Local key on banks table
        'id' // Local key on booklets table
    );
}

    public function booklets(): HasMany
    {
        return $this->hasMany(LibBooklet::class, 'bank_id');
    }

    public function bookletsWithAvailableCheques(): HasMany
    {
        return $this->hasMany(LibBooklet::class, 'bank_id')
                ->where('status', '!=', 'consumed');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class, 'bank_id');
    }

    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

}
