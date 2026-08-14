<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibBooklet extends Model
{

    protected $table = 'lib_booklet';

    protected $casts = [
        'id' => 'integer', // Ensure ID is always treated as integer
        'bank_id' => 'integer',
        'quantity' => 'integer',
    ];

    protected $fillable = [
        'bank_id',
        'booklet_numb',
        'starting_cheque_numb',
        'ending_cheque_numb',
        'quantity',
        'status'
    ];
    public function bank(): BelongsTo
    {
        return $this->belongsTo(LibBank::class);
    }

    public function cheques(): HasMany
    {
        return $this->hasMany(LibCheque::class, 'booklet_id');
    }
}
