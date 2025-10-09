<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Concerns\ScopesBarangay as BarangayScope;

class ContDisbursement extends Model
{
    use HasFactory;

    protected $table = 'cont_disbursement';

    protected $fillable = [
        'barangay_id',
        'date',
        'dv_number',
        'cheque_number',
        'bank_id',
        'payee',
        'dv_amount',
        'status',
        'liquidated_amount',
        'liquidated_at',
        'remarks',
        'rejection_remarks',
        'user_id',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BarangayScope);
    }

    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

    public function orDetails()
    {
        return $this->hasMany(ContDisbursementOrDetail::class, 'cont_disbursement_id');
    }

    public function bank()
    {
        return $this->belongsTo(LibBank::class, 'bank_id');
    }

    public function expenseDetails()
    {
        return $this->hasMany(ContTranExpenseDetail::class, 'cont_disbursement_id');
    }

    public function cheque()
    {
        return $this->hasOne(LibCheque::class, 'disbursement_id');
    }

    public function adminReviews(): MorphMany
    {
        return $this->morphMany(AdminReview::class, 'reviewable');
    }
}