<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Concerns\ScopesBarangay as BarangayScope;
use App\Models\Deduction;

class Disbursement extends Model
{
    use HasFactory;

    protected $table = 'disbursements';

    protected $fillable = [
        'barangay_id',
        'date',
        'dv_number',
        'ref_dv_number',
        'cheque_number',
        'cheque_date',
        'bank_id',
        'payee',
        'payee2',
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
        return $this->belongsTo(Barangay::class,'barangay_id');
    }

    public function orDetails()
    {
        return $this->hasMany(DisbursementOrDetail::class);
    }

    public function bank()
    {
        return $this->belongsTo(LibBank::class, 'bank_id');
    }

    public function expenseDetails()
    {
        return $this->hasMany(TranExpenseDetail::class, 'disbursement_id');
    }

    public function deductions()
    {
        return $this->hasMany(Deduction::class, 'disbursement_id');
    }

    public function cheque()
    {
        return $this->hasOne(LibCheque::class, 'disbursement_id');
    }

    public function adminReviews(): MorphMany
    {
        return $this->morphMany(AdminReview::class, 'reviewable');
    }

    public function cheques()
    {
        return $this->hasMany(
            LibCheque::class,
            'disbursement_id'
        );
    }
}
