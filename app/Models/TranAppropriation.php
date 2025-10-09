<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Concerns\ScopesBarangay as BarangayScope;

class TranAppropriation extends Model
{
    protected $fillable = [
        'barangay_id',
        'budget_id',
        'cont_appropriation_id',
        'expense_class_id',
        'expense_type_id',
        'expense_item_id',
        'expense_sub_item_id',
        'amount',
        'transaction_date',
        'status',
        'user_id'
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2'
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BarangayScope);
    }

    
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class,'barangay_id');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class,'budget_id')->withDefault();
    }

    public function contAppropriation(): BelongsTo
    {
        return $this->belongsTo(ContAppropriation::class, 'cont_appropriation_id');
    }

    public function expenseClass(): BelongsTo
    {
        return $this->belongsTo(LibExpenseClass::class, 'expense_class_id');
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(LibExpenseType::class);
    }

    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(LibExpenseItem::class);
    }

    public function expenseSubItem(): BelongsTo
    {
        return $this->belongsTo(LibExpenseItem::class, 'expense_sub_item_id');
    }
    public function details()
    {
        return $this->hasMany(TranExpenseDetail::class, 'appropriation_id');
    }
    public function continuing()
    {
        return $this->hasMany(ContApproAccounts::class, 'contAppropriation_id');
    }

    public function adminReviews(): MorphMany
    {
        return $this->morphMany(AdminReview::class, 'reviewable');
    }
}
