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
        'ref_dv_number',
        'payee',
        'payee2',
        'dv_amount',

        'bank_status',
        
        'liquidated_amount',
        'status',
        'user_id',
        'liquidated_at',
        'remarks',
        'rejection_remarks',
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

    public function expenseDetails()
    {
        return $this->hasMany(ContTranExpenseDetail::class, 'cont_disbursement_id');
    }

    public function adminReviews(): MorphMany
    {
        return $this->morphMany(AdminReview::class, 'reviewable');
    }

    public function deductions()
    {
        return $this->hasMany(
            ContDeduction::class,
            'cont_disbursement_id'
        );
    }

    public function bankCheques()
    {
        return $this->hasMany(
            ContBankCheque::class,
            'cont_disbursement_id'
        );
    }
}
