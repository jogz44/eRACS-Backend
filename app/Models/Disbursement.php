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

        'payee',
        'payee2',

        'bank_status',

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

    public function expenseDetails()
    {
        return $this->hasMany(TranExpenseDetail::class, 'disbursement_id');
    }

    public function deductions()
    {
        return $this->hasMany(Deduction::class, 'disbursement_id');
    }

    public function adminReviews(): MorphMany
    {
        return $this->morphMany(AdminReview::class, 'reviewable');
    }

    public function bankCheques()
    {
        return $this->hasMany(BankCheque::class, 'disbursement_id');
    }

    //relation for generated txt file
    public function bankExport()
    {
        return $this->hasOne(BankExportFile::class);
    }
}
