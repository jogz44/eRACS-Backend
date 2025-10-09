<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContTranExpenseDetail extends Model
{
    protected $table = 'cont_tran_expense_detail';

    protected $fillable = [
        'cont_disbursement_id',
        'cont_appro_account_id',
        'amount',
        'particulars',
        'user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function contDisbursement()
    {
        return $this->belongsTo(ContDisbursement::class, 'cont_disbursement_id');
    }

    public function contApproAccount()
    {
        return $this->belongsTo(ContApproAccounts::class, 'cont_appro_account_id');
    }

    public function user()
    {
        return $this->belongsTo(BarangayUser::class);
    }
}