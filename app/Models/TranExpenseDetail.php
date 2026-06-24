<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranExpenseDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'disbursement_id',
        'appropriation_id',
        'bank_id',
        'cheque_number',
        'cheque_date',
        'amount',
        'particulars',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Get the disbursement that owns this expense detail.
     */
    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class,'disbursement_id');
    }

    /**
     * Get the appropriation/expense item for this detail.
     */
    public function appropriation()
    {
        return $this->belongsTo(TranAppropriation::class, 'appropriation_id');
    }

    public function bank()
    {
        return $this->belongsTo(LibBank::class, 'bank_id');
    }
}
