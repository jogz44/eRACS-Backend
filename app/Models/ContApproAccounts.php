<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContApproAccounts extends Model
{
    protected $fillable = [
        'contAppropriation_id',
        'tranAppropriation_id',
        'original_amount',
        'current_amount',
        'continuingYear',
        'status',
        'user_id'
    ];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'current_amount' => 'decimal:2'
    ];

    public function continuingAppropriation()
    {
        return $this->belongsTo(ContAppropriation::class, 'contAppropriation_id');
    }

    public function transactionAppropriation()
    {
        return $this->belongsTo(TranAppropriation::class, 'tranAppropriation_id');
    }

    public function user()
    {
        return $this->belongsTo(BarangayUser::class);
    }
}
