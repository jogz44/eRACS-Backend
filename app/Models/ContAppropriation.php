<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContAppropriation extends Model
{
    protected $fillable = [
        'barangay_id',
        'fiscal_year_id',
        'description',
        'expense_class',
        'appropriation_amount',
        'unappropriated_amount',
        'continued_date',
        'status',
        'user_id',
    ];

    protected $casts = [
        'continued_date' => 'date',
        'appropriation_amount' => 'decimal:2',
        'unappropriated_amount' => 'decimal:2',
    ];

    public function continuingAccounts(): HasMany
    {
        return $this->hasMany(
            ContApproAccounts::class,
            'contAppropriation_id'
        );
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(
            Barangay::class,
            'barangay_id'
        );
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(
            LibFiscalYear::class,
            'fiscal_year_id'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            BarangayUser::class,
            'user_id'
        );
    }
}
